<?php

namespace App\Services;

use App\Models\ConversionRule;
use App\Models\InvoiceDistribution;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Converts plumber points → money using each product's own conversion
 * (point_value_fixed / percent), not only the global ConversionRule rate.
 *
 * Lots are rebuilt from awarded tier-3 distribution items (FIFO), minus
 * points already consumed by previous conversions (stored in txn meta).
 */
class PointsConversionService
{
    /**
     * @return array{
     *   points_converted:int,
     *   gross_amount_cents:int,
     *   fee_cents:int,
     *   net_amount_cents:int,
     *   net_amount_formatted:string,
     *   balance_points:int,
     *   balance_cents:int,
     *   breakdown:list<array<string,mixed>>,
     *   mode:string
     * }
     */
    public function convert(User $plumber, int $points, string $currency = 'LYD'): array
    {
        if ($points < 1) {
            throw new \DomainException('عدد النقاط غير صالح');
        }

        $rule = ConversionRule::globalSettings();

        if (! $rule->isRedemptionOpen()) {
            throw new \DomainException('صرف النقاط غير متاح حالياً — خارج فترة السماح');
        }

        $minPoints = (int) $rule->min_redeem_points;
        $maxPoints = $rule->max_redeem_points ? (int) $rule->max_redeem_points : null;

        return DB::transaction(function () use ($plumber, $points, $currency, $rule, $minPoints, $maxPoints) {
            $wallet = WalletAccount::query()
                ->where('owner_id', $plumber->id)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                throw new \DomainException('لا توجد محفظة لهذا الحساب');
            }

            $balance = (int) $wallet->balance_points;

            if ($balance < $points) {
                throw new \DomainException("رصيدك غير كافٍ. رصيدك الحالي {$balance} نقطة وطلبت تحويل {$points}");
            }

            $isFullBalance = $points === $balance;

            if ($points < $minPoints && ! $isFullBalance) {
                throw new \DomainException(
                    "الحد الأدنى للتحويل هو {$minPoints} نقطة. رصيدك {$balance} نقطة — يمكنك تحويل الرصيد بالكامل (points={$balance})."
                );
            }

            if ($maxPoints && $points > $maxPoints) {
                throw new \DomainException("الحد الأقصى للتحويل هو {$maxPoints} نقطة");
            }

            $lots = $this->availableLots($plumber, $wallet);
            $allocation = $this->allocatePoints($lots, $points, $rule);

            $grossCents = (int) collect($allocation)->sum('amount_cents');
            if ($grossCents <= 0) {
                throw new \DomainException('إعدادات التحويل غير مكتملة — لا يوجد سعر تحويل للمنتجات أو السعر العام');
            }

            $feeCents = (int) floor($grossCents * ((float) $rule->fee_percent / 100)) + (int) $rule->fee_fixed_cents;
            $netCents = max(0, $grossCents - $feeCents);

            $wallet->decrement('balance_points', $points);
            $wallet->increment('balance_cents', $netCents);

            $wallet->transactions()->create([
                'type' => 'conversion',
                'amount_cents' => $netCents,
                'points_delta' => -$points,
                'description' => 'تحويل نقاط إلى رصيد مالي (حسب منتجات الفواتير)',
                'meta' => [
                    'reason' => 'conversion',
                    'mode' => 'per_product',
                    'gross_amount_cents' => $grossCents,
                    'fee_cents' => $feeCents,
                    'rule_id' => $rule->id,
                    'lots_consumed' => $allocation,
                    'global_points_per_currency_unit' => (float) $rule->points_per_currency_unit,
                ],
                'related_type' => ConversionRule::class,
                'related_id' => $rule->id,
                'created_by' => $plumber->id,
            ]);

            $fresh = $wallet->fresh();

            return [
                'points_converted' => $points,
                'gross_amount_cents' => $grossCents,
                'fee_cents' => $feeCents,
                'net_amount_cents' => $netCents,
                'net_amount_formatted' => number_format($netCents / 100, 2).' د.ل',
                'balance_points' => (int) $fresh->balance_points,
                'balance_cents' => (int) $fresh->balance_cents,
                'breakdown' => $allocation,
                'mode' => 'per_product',
            ];
        });
    }

    /**
     * Preview remaining redeemable lots for UI.
     *
     * @return list<array<string,mixed>>
     */
    public function previewLots(User $plumber, string $currency = 'LYD'): array
    {
        $wallet = $plumber->wallet($currency);

        return $this->availableLots($plumber, $wallet)->map(function (array $lot) {
            return [
                'product_id' => $lot['product_id'],
                'product_name' => $lot['product_name'],
                'points_remaining' => $lot['points_remaining'],
                'lyd_per_point' => $lot['lyd_per_point'],
                'estimated_cents' => (int) floor($lot['points_remaining'] * $lot['lyd_per_point'] * 100),
                'estimated_formatted' => number_format($lot['points_remaining'] * $lot['lyd_per_point'], 2).' د.ل',
                'rate_source' => $lot['rate_source'],
                'conversion_summary' => $lot['conversion_summary'],
            ];
        })->values()->all();
    }

    /**
     * @return Collection<int, array{
     *   key:string,
     *   product_id:?int,
     *   product_name:string,
     *   points_remaining:int,
     *   lyd_per_point:float,
     *   rate_source:string,
     *   conversion_summary:string,
     *   awarded_at:?string
     * }>
     */
    protected function availableLots(User $plumber, WalletAccount $wallet): Collection
    {
        $rule = ConversionRule::globalSettings();
        $globalLydPerPoint = $this->globalLydPerPoint($rule);

        $awarded = InvoiceDistribution::query()
            ->where('to_user_id', $plumber->id)
            ->where('tier', 3)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->with([
                'items.invoiceItem.product.translations',
            ])
            ->orderBy('points_awarded_at')
            ->orderBy('id')
            ->get();

        $lots = collect();

        foreach ($awarded as $distribution) {
            foreach ($distribution->items as $item) {
                $qty = max(0, (int) $item->quantity - (int) ($item->returned_quantity ?? 0));
                if ($qty <= 0 || (int) $item->quantity <= 0) {
                    continue;
                }

                // Scale original points by remaining quantity after returns
                $points = (int) floor(((int) $item->points_value) * ($qty / (int) $item->quantity));
                if ($points <= 0) {
                    continue;
                }

                $product = $item->invoiceItem?->product;
                $productId = $product?->id ?? $item->invoiceItem?->product_id;
                $rate = $this->resolveLydPerPoint($product, $globalLydPerPoint);

                $lots->push([
                    'key' => 'd'.$distribution->id.'-i'.$item->id,
                    'distribution_id' => $distribution->id,
                    'distribution_item_id' => $item->id,
                    'product_id' => $productId ? (int) $productId : null,
                    'product_name' => $product
                        ? (localized_name($product, 'name') ?: ('منتج #'.$productId))
                        : ('بند #'.$item->id),
                    'points_awarded' => $points,
                    'points_remaining' => $points,
                    'lyd_per_point' => $rate['lyd_per_point'],
                    'rate_source' => $rate['source'],
                    'conversion_summary' => $rate['summary'],
                    'awarded_at' => optional($distribution->points_awarded_at ?? $distribution->confirmed_at)->toIso8601String(),
                ]);
            }
        }

        // Consume points already converted
        $consumedByKey = [];
        $consumedUnkeyed = 0;

        $wallet->transactions()
            ->where('type', 'conversion')
            ->orderBy('id')
            ->get(['meta', 'points_delta'])
            ->each(function (WalletTransaction $tx) use (&$consumedByKey, &$consumedUnkeyed) {
                $lotsMeta = data_get($tx->meta, 'lots_consumed', []);
                if (is_array($lotsMeta) && $lotsMeta !== []) {
                    foreach ($lotsMeta as $row) {
                        $key = (string) ($row['lot_key'] ?? '');
                        $pts = (int) ($row['points'] ?? 0);
                        if ($pts <= 0) {
                            continue;
                        }
                        if ($key !== '' && $key !== 'overflow' && $key !== 'residual') {
                            $consumedByKey[$key] = ($consumedByKey[$key] ?? 0) + $pts;
                        } else {
                            $consumedUnkeyed += $pts;
                        }
                    }

                    return;
                }

                $consumedUnkeyed += abs((int) $tx->points_delta);
            });

        $lots = $lots->map(function (array $lot) use (&$consumedByKey) {
            $used = (int) ($consumedByKey[$lot['key']] ?? 0);
            $lot['points_remaining'] = max(0, $lot['points_awarded'] - $used);

            return $lot;
        });

        // Apply unkeyed/legacy consumption FIFO across remaining lots
        if ($consumedUnkeyed > 0) {
            $lots = $lots->map(function (array $lot) use (&$consumedUnkeyed) {
                if ($consumedUnkeyed <= 0 || $lot['points_remaining'] <= 0) {
                    return $lot;
                }
                $take = min($lot['points_remaining'], $consumedUnkeyed);
                $lot['points_remaining'] -= $take;
                $consumedUnkeyed -= $take;

                return $lot;
            });
        }

        // Residual wallet points not explained by distribution lots (manual adjustments etc.)
        $explained = (int) $lots->sum('points_remaining');
        $walletPoints = (int) $wallet->balance_points;
        $residual = max(0, $walletPoints - $explained);

        if ($residual > 0) {
            $lots->push([
                'key' => 'residual',
                'distribution_id' => null,
                'distribution_item_id' => null,
                'product_id' => null,
                'product_name' => 'رصيد عام (بدون ربط منتج)',
                'points_awarded' => $residual,
                'points_remaining' => $residual,
                'lyd_per_point' => $globalLydPerPoint,
                'rate_source' => 'global_rule',
                'conversion_summary' => 'سعر الصرف العام',
                'awarded_at' => null,
            ]);
        }

        return $lots->filter(fn (array $lot) => $lot['points_remaining'] > 0)->values();
    }

    /**
     * @param  Collection<int, array<string,mixed>>  $lots
     * @return list<array<string,mixed>>
     */
    protected function allocatePoints(Collection $lots, int $points, ConversionRule $rule): array
    {
        $remaining = $points;
        $allocation = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((int) $lot['points_remaining'], $remaining);
            if ($take <= 0) {
                continue;
            }

            $lydPerPoint = (float) $lot['lyd_per_point'];
            if ($lydPerPoint <= 0) {
                $lydPerPoint = $this->globalLydPerPoint($rule);
            }

            $amountCents = (int) floor($take * $lydPerPoint * 100);

            $allocation[] = [
                'lot_key' => $lot['key'],
                'product_id' => $lot['product_id'],
                'product_name' => $lot['product_name'],
                'points' => $take,
                'lyd_per_point' => $lydPerPoint,
                'amount_cents' => $amountCents,
                'amount_formatted' => number_format($amountCents / 100, 2).' د.ل',
                'rate_source' => $lot['rate_source'],
                'conversion_summary' => $lot['conversion_summary'],
            ];

            $remaining -= $take;
        }

        if ($remaining > 0) {
            // Safety: leftover at global rate
            $lyd = $this->globalLydPerPoint($rule);
            $amountCents = (int) floor($remaining * $lyd * 100);
            $allocation[] = [
                'lot_key' => 'overflow',
                'product_id' => null,
                'product_name' => 'رصيد متبقٍ',
                'points' => $remaining,
                'lyd_per_point' => $lyd,
                'amount_cents' => $amountCents,
                'amount_formatted' => number_format($amountCents / 100, 2).' د.ل',
                'rate_source' => 'global_rule',
                'conversion_summary' => 'سعر الصرف العام',
            ];
        }

        return $allocation;
    }

    /** @return array{lyd_per_point:float, source:string, summary:string} */
    protected function resolveLydPerPoint(?Product $product, float $globalLydPerPoint): array
    {
        if (! $product) {
            return [
                'lyd_per_point' => $globalLydPerPoint,
                'source' => 'global_rule',
                'summary' => 'سعر الصرف العام',
            ];
        }

        $perPoint = (float) $product->pointMonetaryValuePerPoint();

        if ($perPoint > 0) {
            return [
                'lyd_per_point' => $perPoint,
                'source' => 'product:'.$product->point_value_type,
                'summary' => $product->pointConversionSummary(),
            ];
        }

        return [
            'lyd_per_point' => $globalLydPerPoint,
            'source' => 'global_rule_fallback',
            'summary' => 'لا إعداد تحويل للمنتج — استخدم السعر العام',
        ];
    }

    protected function globalLydPerPoint(ConversionRule $rule): float
    {
        $rate = (float) $rule->points_per_currency_unit;

        if ($rate <= 0) {
            return 0.0;
        }

        // points_per_currency_unit = points needed for 1 LYD
        return round(1 / $rate, 6);
    }
}

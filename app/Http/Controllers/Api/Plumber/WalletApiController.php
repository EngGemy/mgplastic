<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\WalletResource;
use App\Http\Resources\Mobile\WalletTransactionResource;
use App\Models\ConversionRule;
use App\Services\PointsConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletApiController extends Controller
{
    use ApiResponds;

    public function show(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet('LYD');
        $wallet->load(['transactions' => fn ($q) => $q->latest()->limit(30)]);

        return $this->success((new WalletResource($wallet))->resolve());
    }

    public function transactions(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet('LYD');

        $query = $wallet->transactions()->latest();

        // ?points_only=1 → حركات النقاط فقط
        if ($request->boolean('points_only')) {
            $query->where('points_delta', '!=', 0);
        }

        $transactions = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'balance_points' => (int) $wallet->balance_points,
            'items' => WalletTransactionResource::collection($transactions->items())->resolve(),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function conversionRules(Request $request): JsonResponse
    {
        $rule = ConversionRule::globalSettings();
        $rate = (float) $rule->points_per_currency_unit;
        $globalLydPerPoint = $rate > 0 ? round(1 / $rate, 6) : 0.0;

        $lots = app(PointsConversionService::class)
            ->previewLots($request->user(), 'LYD');

        $lotsGrossCents = (int) collect($lots)->sum('estimated_cents');
        $lotsFeeCents = (int) floor($lotsGrossCents * ((float) $rule->fee_percent / 100)) + (int) $rule->fee_fixed_cents;
        $lotsNetCents = max(0, $lotsGrossCents - $lotsFeeCents);

        return $this->success([
            'is_active' => $rule->is_active,
            'is_redemption_open' => $rule->isRedemptionOpen(),
            'currency' => $rule->currency ?? 'LYD',
            'points_per_currency_unit' => $rate,
            'global_lyd_per_point' => $globalLydPerPoint,
            'min_redeem_points' => (int) $rule->min_redeem_points,
            'max_redeem_points' => $rule->max_redeem_points ? (int) $rule->max_redeem_points : null,
            'fee_percent' => (float) $rule->fee_percent,
            'fee_fixed_cents' => (int) $rule->fee_fixed_cents,
            'starts_at' => $rule->starts_at?->toIso8601String(),
            'ends_at' => $rule->ends_at?->toIso8601String(),
            'mode' => 'per_product',
            'how_it_works' => [
                'earn' => 'النقاط تُكتسب من الفاتورة: نقاط البند = الكمية × points_per_unit للمنتج',
                'convert' => 'كل منتج له تحويله الخاص (point_value_fixed أو percent). التحويل يستهلك النقاط FIFO من فواتير المنتجات ثم يطبّق سعر كل منتج',
                'fallback' => 'لو المنتج بدون إعداد تحويل → يُستخدم السعر العام points_per_currency_unit',
                'withdraw' => 'السحب من balance_cents بعد التحويل (مش مباشرة من النقاط)',
            ],
            'formula' => [
                'per_lot_cents' => 'floor(points_from_product × lyd_per_point × 100)',
                'gross_cents' => 'sum(per_lot_cents)',
                'fee_cents' => 'floor(gross_cents * fee_percent / 100) + fee_fixed_cents',
                'net_cents' => 'max(0, gross_cents - fee_cents)',
                'note' => '100 cents = 1.00 د.ل — الرقم النهائي يعتمد على مزيج المنتجات وليس السعر العام وحده',
            ],
            'product_lots' => $lots,
            'estimated_full_balance' => [
                'points' => (int) collect($lots)->sum('points_remaining'),
                'gross_amount_cents' => $lotsGrossCents,
                'fee_cents' => $lotsFeeCents,
                'net_amount_cents' => $lotsNetCents,
                'net_amount_formatted' => number_format($lotsNetCents / 100, 2).' د.ل',
            ],
        ]);
    }
}

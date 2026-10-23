<?php

namespace App\Services\Points;

use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductPointsRateChange;

/**
 * Builds a clear Arabic hint when the factory/catalog points rate
 * differs from the rate frozen on a received stock lot / invoice line.
 *
 * Sale math still uses the lot receive snapshot; this only explains the mismatch.
 */
final class PointsRateHintBuilder
{
    private const EPSILON = 0.00005;

    public function __construct(
        private readonly CatalogPointsPolicy $policy,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forLot(
        ?Product $product,
        float $lotReceivePerUnit,
        ?ProductPointsRateChange $latestChange = null,
        bool $includeRates = true,
    ): ?array {
        $lot = max(0, $lotReceivePerUnit);
        if ($lot <= 0 || $product === null) {
            return null;
        }

        $catalog = $this->policy->catalogRate($product);
        if (abs($catalog - $lot) <= self::EPSILON) {
            return null;
        }

        $latestChange ??= $product->relationLoaded('latestPointsRateChange')
            ? $product->latestPointsRateChange
            : $product->latestPointsRateChange()->first();

        $direction = $catalog > $lot ? 'up' : 'down';
        $fmt = fn (float $v): string => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';

        if ($direction === 'up') {
            $title = 'المصنع رفع سعر نقاط المنتج';
            $message = sprintf(
                'تم تعديل سعر النقاط من المصنع إلى %s نقطة/وحدة. مخزونك من فاتورة الاستلام ما زال يُحسب بالسعر المثبت %s نقطة/وحدة حتى ينفد؛ الفواتير الجديدة من المصنع تأخذ السعر الجديد.',
                $fmt($catalog),
                $fmt($lot),
            );
        } else {
            $title = 'المصنع خفّض سعر نقاط المنتج';
            $message = sprintf(
                'تم تعديل سعر النقاط من المصنع إلى %s نقطة/وحدة. مخزونك الحالي يظل على سعر الاستلام المثبت %s نقطة/وحدة حتى ينفد.',
                $fmt($catalog),
                $fmt($lot),
            );
        }

        $payload = [
            'code' => 'catalog_changed_since_lot',
            'title_ar' => $title,
            'message_ar' => $message,
            'direction' => $direction,
            'sale_uses' => 'lot_receive',
            'highlighted' => true,
            'tone' => $direction === 'up' ? 'warning' : 'info',
            'icon' => $direction === 'up' ? 'arrow_up' : 'arrow_down',
        ];

        if ($includeRates) {
            $payload['catalog_points_per_unit'] = round($catalog, 4);
            $payload['lot_receive_points_per_unit'] = round($lot, 4);
            $payload['delta_points_per_unit'] = round($catalog - $lot, 4);
        }

        if ($latestChange) {
            $payload['change_id'] = (int) $latestChange->id;
            $payload['catalog_changed_at'] = $latestChange->created_at
                ? $latestChange->created_at->clone()->timezone('Africa/Tripoli')->toIso8601String()
                : null;
            $payload['change_old'] = round((float) $latestChange->old_points_per_unit, 4);
            $payload['change_new'] = round((float) $latestChange->new_points_per_unit, 4);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forInvoiceItem(InvoiceItem $item, bool $includeRates = true): ?array
    {
        $item->loadMissing(['product.latestPointsRateChange']);

        return $this->forLot(
            $item->product,
            (float) $item->points_per_unit,
            $item->product?->latestPointsRateChange,
            $includeRates,
        );
    }

    /**
     * Aggregate product-level hint when any slot differs from live catalog.
     *
     * @param  list<array<string, mixed>>  $slotHints
     * @return array<string, mixed>|null
     */
    public function forProductSlots(array $slotHints, ?float $catalogPerUnit = null): ?array
    {
        $active = array_values(array_filter($slotHints));
        if ($active === []) {
            return null;
        }

        $first = $active[0];
        $directions = array_unique(array_column($active, 'direction'));
        $mixed = count($directions) > 1
            || count(array_unique(array_map(
                fn ($h) => (string) ($h['lot_receive_points_per_unit'] ?? ''),
                $active,
            ))) > 1;

        if ($mixed) {
            $catalog = $catalogPerUnit ?? ($first['catalog_points_per_unit'] ?? null);
            $fmt = is_numeric($catalog)
                ? (rtrim(rtrim(number_format((float) $catalog, 4, '.', ''), '0'), '.') ?: '0')
                : null;

            return [
                'code' => 'catalog_changed_mixed_lots',
                'title_ar' => 'سعر نقاط المصنع تغيّر — لديك دفعات بأسعار مختلفة',
                'message_ar' => $fmt
                    ? "المصنع عدّل سعر الكتالوج إلى {$fmt} نقطة/وحدة. لديك مخزون من فواتير قديمة بأسعار استلام مختلفة؛ كل دفعة تُباع بسعرها المثبت حتى تنفد."
                    : 'المصنع عدّل سعر نقاط المنتج. لديك مخزون من فواتير قديمة بأسعار استلام مختلفة؛ كل دفعة تُباع بسعرها المثبت حتى تنفد.',
                'direction' => 'mixed',
                'sale_uses' => 'lot_receive',
                'highlighted' => true,
                'tone' => 'warning',
                'icon' => 'layers',
                'lots_affected' => count($active),
            ];
        }

        $first['lots_affected'] = count($active);

        return $first;
    }

    /**
     * Mobile-safe projection: keep messaging + codes; drop raw rates if hidden.
     *
     * @param  array<string, mixed>|null  $hint
     * @return array<string, mixed>|null
     */
    public function forMobile(?array $hint, bool $hideRates = true): ?array
    {
        if ($hint === null) {
            return null;
        }

        if (! $hideRates) {
            return $hint;
        }

        return array_diff_key($hint, array_flip([
            'catalog_points_per_unit',
            'lot_receive_points_per_unit',
            'delta_points_per_unit',
            'change_old',
            'change_new',
        ]));
    }
}

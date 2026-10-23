<?php

namespace App\Services;

use App\Filament\Pages\ProductPointsChangesReport;
use App\Models\Product;
use App\Models\ProductPointsRateChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Catalog points rate is the source of truth for NEW sales (unified rate),
 * even when fulfilling from stock received at an older invoice rate.
 * Historical invoice lines stay untouched; rate changes are logged here.
 */
class ProductPointsRateService
{
    public function __construct(
        protected NetworkInventoryService $inventory,
    ) {}

    /**
     * @return array{
     *   old_points_per_unit:float,
     *   new_points_per_unit:float,
     *   delta_points_per_unit:float,
     *   change_percent:?float,
     *   wholesale_remaining_qty:int,
     *   retail_remaining_qty:int,
     *   wholesale_holders:int,
     *   retail_holders:int,
     *   wholesale_by_trader:list<array{user_id:int, name:string, qty:int}>,
     *   retail_by_trader:list<array{user_id:int, name:string, qty:int}>,
     *   estimated_points_at_old:int,
     *   estimated_points_at_new:int,
     *   estimated_points_delta:int,
     *   total_remaining_qty:int
     * }
     */
    public function estimateImpact(Product $product, float $newPointsPerUnit): array
    {
        $old = round((float) ($product->points_per_unit ?? 0), 4);
        $new = round(max(0, $newPointsPerUnit), 4);
        $delta = round($new - $old, 4);
        $percent = $old > 0 ? round(($delta / $old) * 100, 2) : null;

        $network = $this->inventory->networkRemainingForProduct((int) $product->id);
        $totalQty = $network['wholesale_remaining_qty'] + $network['retail_remaining_qty'];
        $atOld = (int) floor($totalQty * $old);
        $atNew = (int) floor($totalQty * $new);

        return [
            'old_points_per_unit' => $old,
            'new_points_per_unit' => $new,
            'delta_points_per_unit' => $delta,
            'change_percent' => $percent,
            'wholesale_remaining_qty' => $network['wholesale_remaining_qty'],
            'retail_remaining_qty' => $network['retail_remaining_qty'],
            'wholesale_holders' => $network['wholesale_holders'],
            'retail_holders' => $network['retail_holders'],
            'wholesale_by_trader' => $network['wholesale_by_trader'] ?? [],
            'retail_by_trader' => $network['retail_by_trader'] ?? [],
            'estimated_points_at_old' => $atOld,
            'estimated_points_at_new' => $atNew,
            'estimated_points_delta' => $atNew - $atOld,
            'total_remaining_qty' => $totalQty,
        ];
    }

    /**
     * Apply a catalog rate change: update product, log history, notify admins.
     */
    public function applyChange(
        Product $product,
        float $newPointsPerUnit,
        ?User $actor = null,
        ?string $note = null,
        bool $notifyAdmins = true,
    ): ProductPointsRateChange {
        $new = round(max(0, $newPointsPerUnit), 4);
        $old = round((float) ($product->points_per_unit ?? 0), 4);

        if (abs($new - $old) < 0.00005) {
            throw new \DomainException('القيمة الجديدة مطابقة للقيمة الحالية — لا يوجد تغيير');
        }

        if ($new <= 0) {
            throw new \DomainException('قيمة النقاط يجب أن تكون أكبر من صفر');
        }

        $impact = $this->estimateImpact($product, $new);

        $change = DB::transaction(function () use ($product, $new, $impact, $actor, $note) {
            $product->pointsRateAudited = true;
            $product->points_per_unit = $new;
            $product->save();

            return $this->createHistoryRow($product, $impact, $actor, $note);
        });

        if ($notifyAdmins) {
            $this->notifyAdmins($product->fresh(['translations']) ?? $product, $change);
        }

        return $change->load(['product.translations', 'author']);
    }

    /**
     * Record history for a change already persisted on the product (e.g. Filament edit / sheet import).
     */
    public function auditPersistedChange(
        Product $product,
        float $oldPointsPerUnit,
        ?User $actor = null,
        ?string $note = null,
        bool $notifyAdmins = true,
    ): ?ProductPointsRateChange {
        if ($product->pointsRateAudited) {
            return null;
        }

        $old = round($oldPointsPerUnit, 4);
        $new = round((float) ($product->points_per_unit ?? 0), 4);

        if (abs($new - $old) < 0.00005) {
            return null;
        }

        // Impact uses current product PPU as "new"; temporarily estimate against old base.
        $snapshotProduct = clone $product;
        $snapshotProduct->points_per_unit = $old;
        $impact = $this->estimateImpact($snapshotProduct, $new);

        $change = $this->createHistoryRow($product, $impact, $actor, $note);
        $product->pointsRateAudited = true;

        if ($notifyAdmins) {
            $this->notifyAdmins($product->loadMissing('translations'), $change);
        }

        return $change->load(['product.translations', 'author']);
    }

    /**
     * Current catalog rate for NEW sales (unified), regardless of slot receive rate.
     * Delegates to CatalogPointsPolicy (single pricing policy).
     */
    public function catalogRateForProduct(?Product $product, ?float $fallback = null): float
    {
        return app(\App\Services\Points\CatalogPointsPolicy::class)
            ->catalogRate($product, $fallback);
    }

    /**
     * @param  array<string, mixed>  $impact
     */
    protected function createHistoryRow(
        Product $product,
        array $impact,
        ?User $actor,
        ?string $note,
    ): ProductPointsRateChange {
        return ProductPointsRateChange::query()->create([
            'product_id' => $product->id,
            'changed_by' => $actor?->id,
            'old_points_per_unit' => $impact['old_points_per_unit'],
            'new_points_per_unit' => $impact['new_points_per_unit'],
            'delta_points_per_unit' => $impact['delta_points_per_unit'],
            'change_percent' => $impact['change_percent'],
            'wholesale_remaining_qty' => $impact['wholesale_remaining_qty'],
            'retail_remaining_qty' => $impact['retail_remaining_qty'],
            'wholesale_holders' => $impact['wholesale_holders'],
            'retail_holders' => $impact['retail_holders'],
            'estimated_points_delta' => $impact['estimated_points_delta'],
            'note' => $note ? mb_substr(trim($note), 0, 500) : null,
            'impact_snapshot' => $impact,
        ]);
    }

    protected function notifyAdmins(Product $product, ProductPointsRateChange $change): void
    {
        $name = localized_name($product, 'name', 'منتج #'.$product->id);
        $dir = $change->direction() === 'up' ? 'زيادة' : 'تخفيض';
        $pct = $change->change_percent !== null
            ? number_format((float) $change->change_percent, 1).'%'
            : '—';
        $body = sprintf(
            "«%s»: من %s إلى %s نقطة/وحدة (%s %s).\nمخزون متبقٍ — جملة: %s · قطاعي: %s.\nتقدير فرق النقاط لو صُرف المتبقي بالسعر الجديد: %+d نقطة.",
            $name,
            number_format((float) $change->old_points_per_unit, 2),
            number_format((float) $change->new_points_per_unit, 2),
            $dir,
            $pct,
            number_format((int) $change->wholesale_remaining_qty),
            number_format((int) $change->retail_remaining_qty),
            (int) $change->estimated_points_delta,
        );

        $url = null;
        try {
            $url = ProductPointsChangesReport::getUrl();
        } catch (\Throwable) {
            $url = '/admin/product-points-changes-report';
        }

        AdminNotificationService::sendToMany(
            User::query()->whereIn('role', ['super_admin', 'admin', 'monitor'])->get(),
            'تغيير سعر نقاط منتج',
            $body,
            $change->direction() === 'up' ? 'warning' : 'info',
            $url,
            'عرض السجل',
            [
                'type' => 'points.rate_changed',
                'screen' => 'admin_points_rates',
                'product_id' => $product->id,
                'change_id' => $change->id,
            ],
            sendPush: false,
        );
    }
}

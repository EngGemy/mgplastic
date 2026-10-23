<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\ProductPointsRateService;
use Illuminate\Support\Facades\Log;

class ProductObserver
{
    public function updating(Product $product): void
    {
        if ($product->isDirty('points_per_unit')) {
            $product->pointsRateOldValue = round((float) ($product->getOriginal('points_per_unit') ?? 0), 4);
        }
    }

    public function saved(Product $product): void
    {
        $points = (float) ($product->points_per_unit ?? 0);

        // مسار API / الاستيراد / أي حفظ خارج صفحة تعديل المنتج في Filament
        if (
            $product->wasChanged('points_per_unit')
            && $product->pointsRateOldValue !== null
            && ! $product->pointsRateAudited
            && $product->exists
            && $points > 0
        ) {
            try {
                app(ProductPointsRateService::class)->auditPersistedChange(
                    $product,
                    (float) $product->pointsRateOldValue,
                    auth()->user(),
                    note: 'تغيير من تحديث المنتج',
                    notifyAdmins: true,
                );
            } catch (\Throwable $e) {
                Log::warning('product_points_rate_audit_failed', [
                    'product_id' => $product->id,
                    'message' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        if ($points <= 0) {
            $name = $product->translate('ar')?->name
                ?? $product->translate('en')?->name
                ?? 'منتج #'.$product->id;

            AdminNotificationService::sendToMany(
                User::query()->whereIn('role', ['super_admin', 'admin'])->get(),
                'منتج بدون نقاط',
                "المنتج «{$name}» لا يحمل قيمة نقاط — لن يظهر في نقاط البيع",
                'warning',
                "/admin/products/{$product->id}/edit",
            );
        }
    }

    public function creating(Product $product): void
    {
        // منع إنشاء منتج بدون نقاط من الـ API
        // (الـ Filament form يتحكم فيه بـ validation)
    }
}

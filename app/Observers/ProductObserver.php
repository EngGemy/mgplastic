<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\ProductPointsRateService;
use App\Services\ProductTypeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        try {
            app(ProductTypeService::class)->forgetHomeCountsCache();
        } catch (\Throwable) {
        }

        $this->deleteReplacedImageFiles($product);

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

    public function deleted(Product $product): void
    {
        try {
            app(ProductTypeService::class)->forgetHomeCountsCache();
        } catch (\Throwable) {
        }

        foreach (['main_image', 'catalog_image_path', 'image_thumb'] as $field) {
            $path = $product->getAttribute($field);
            if (filled($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    protected function deleteReplacedImageFiles(Product $product): void
    {
        foreach (['main_image', 'catalog_image_path'] as $field) {
            if (! $product->wasChanged($field)) {
                continue;
            }
            $old = $product->getOriginal($field);
            $new = $product->getAttribute($field);
            if (filled($old) && $old !== $new) {
                Storage::disk('public')->delete($old);
            }
        }

        if ($product->wasChanged('image_thumb')) {
            $oldThumb = $product->getOriginal('image_thumb');
            $newThumb = $product->getAttribute('image_thumb');
            if (filled($oldThumb) && $oldThumb !== $newThumb) {
                Storage::disk('public')->delete($oldThumb);
            }
        }
    }
}

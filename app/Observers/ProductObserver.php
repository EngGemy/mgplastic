<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\User;
use App\Services\AdminNotificationService;

class ProductObserver
{
    public function saved(Product $product): void
    {
        $points = (float) ($product->points_per_unit ?? 0);

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

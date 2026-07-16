<?php

namespace App\Services;

use App\Models\User;

class StoreApprovalService
{
    public function approve(User $store, ?User $actor = null): User
    {
        $store->forceFill([
            'is_approved' => true,
            'approved_at' => now(),
            'is_active' => true,
        ])->save();

        AdminNotificationService::send(
            $store,
            'تم تفعيل متجرك ✓',
            'وافق الإدارة على حساب متجرك. يمكنك الآن الظهور في الشبكة واستخدام كل الميزات.',
            'success',
        );

        return $store;
    }

    public function reject(User $store, ?string $reason = null): User
    {
        $store->forceFill([
            'is_approved' => false,
            'is_active' => false,
            'deactivated_at' => now(),
        ])->save();

        $body = 'لم تتم الموافقة على حساب متجرك حالياً.';
        if ($reason) {
            $body .= ' السبب: '.$reason;
        }

        AdminNotificationService::send($store, 'طلب التفعيل مرفوض', $body, 'danger');

        return $store;
    }

    public static function pendingWholesaleCount(): int
    {
        return User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_approved', false)
            ->count();
    }
}

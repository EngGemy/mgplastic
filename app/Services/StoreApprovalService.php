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
            'deactivated_at' => null,
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

    /** Start / resume store activity (after it was already approved). */
    public function activate(User $store): User
    {
        $store->forceFill([
            'is_active' => true,
            'deactivated_at' => null,
            'is_approved' => true,
            'approved_at' => $store->approved_at ?? now(),
        ])->save();

        AdminNotificationService::send(
            $store,
            'تم تفعيل نشاط متجرك ✓',
            'أعادت الإدارة تفعيل حسابك. يمكنك تسجيل الدخول والظهور في الشبكة مجدداً.',
            'success',
        );

        return $store;
    }

    /** Suspend store activity without deleting the account. */
    public function deactivate(User $store, ?string $reason = null): User
    {
        $store->forceFill([
            'is_active' => false,
            'deactivated_at' => now(),
        ])->save();

        $body = 'تم إيقاف نشاط متجرك من قبل الإدارة.';
        if ($reason) {
            $body .= ' السبب: '.$reason;
        }

        AdminNotificationService::send($store, 'تم إيقاف نشاط المتجر', $body, 'warning');

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

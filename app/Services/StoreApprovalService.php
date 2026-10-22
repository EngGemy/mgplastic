<?php

namespace App\Services;

use App\Models\User;
use App\Services\Notifications\DomainEventNotifier;

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

        app(DomainEventNotifier::class)->accountActivated(
            $store,
            $store->isRetailTrader() ? 'تم تفعيل حسابك ✓' : 'تم تفعيل متجرك ✓',
            $store->isRetailTrader()
                ? 'وافقت الإدارة على حسابك كتاجر قطاعي. يمكنك الآن استخدام النظام والظهور في الشبكة.'
                : 'وافق الإدارة على حساب متجرك. يمكنك الآن الظهور في الشبكة واستخدام كل الميزات.',
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

        $body = $store->isRetailTrader()
            ? 'لم تتم الموافقة على حسابك كتاجر قطاعي حالياً.'
            : 'لم تتم الموافقة على حساب متجرك حالياً.';
        if ($reason) {
            $body .= ' السبب: '.$reason;
        }

        app(DomainEventNotifier::class)->accountSuspended($store, $body);

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

        app(DomainEventNotifier::class)->accountActivated(
            $store,
            $store->isRetailTrader() ? 'تم تفعيل نشاط حسابك ✓' : 'تم تفعيل نشاط متجرك ✓',
            $store->isRetailTrader()
                ? 'أعادت الإدارة تفعيل حسابك. يمكنك تسجيل الدخول والعمل على النظام مجدداً.'
                : 'أعادت الإدارة تفعيل حسابك. يمكنك تسجيل الدخول والظهور في الشبكة مجدداً.',
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

        $body = $store->isRetailTrader()
            ? 'تم إيقاف نشاط حسابك من قبل الإدارة.'
            : 'تم إيقاف نشاط متجرك من قبل الإدارة.';
        if ($reason) {
            $body .= ' السبب: '.$reason;
        }

        app(DomainEventNotifier::class)->accountSuspended($store, $body);

        return $store;
    }

    public static function pendingWholesaleCount(): int
    {
        return User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_approved', false)
            ->count();
    }

    public static function pendingRetailCount(): int
    {
        return User::query()
            ->where('role', 'retail_trader')
            ->where('is_approved', false)
            ->count();
    }
}

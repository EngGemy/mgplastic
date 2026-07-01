<?php

namespace App\Services;

use App\Models\InvoiceDistribution;
use App\Models\User;
use App\Models\WithdrawalRequest;

class MobileDashboardService
{
    /** @return array<string, mixed> */
    public static function forPlumber(User $user): array
    {
        $wallet = $user->wallet('LYD');

        return [
            'role' => 'plumber',
            'wallet' => [
                'balance_cents' => $wallet->balance_cents,
                'balance_points' => (int) $wallet->balance_points,
            ],
            'pending_withdrawals' => WithdrawalRequest::query()
                ->where('plumber_id', $user->id)
                ->when(
                    \Illuminate\Support\Facades\Schema::hasColumn('withdrawal_requests', 'status'),
                    fn ($q) => $q->where('status', 'pending'),
                )
                ->count(),
            'received_distributions' => InvoiceDistribution::query()
                ->where('to_user_id', $user->id)
                ->where('tier', 3)
                ->count(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forRetailTrader(User $user): array
    {
        $wallet = app(NetworkInventoryService::class)->walletBalance($user);
        $stock = app(NetworkInventoryService::class)->stockForRetailTrader($user);

        return [
            'role' => 'retail_trader',
            'wallet' => $wallet,
            'stock_products' => $stock->count(),
            'total_available_qty' => (int) $stock->sum('available_qty'),
            'total_available_points' => (int) $stock->sum('available_points'),
            'plumbers_count' => User::query()
                ->where('role', 'plumber')
                ->where('parent_distributor_id', $user->id)
                ->where('is_active', true)
                ->count(),
            'distributions_sent' => InvoiceDistribution::query()
                ->where('from_user_id', $user->id)
                ->count(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forWholesaler(User $user): array
    {
        $wallet = app(NetworkInventoryService::class)->walletBalance($user);
        $stock = app(NetworkInventoryService::class)->stockForWholesaler($user);

        return [
            'role' => 'wholesale_distributor',
            'wallet' => $wallet,
            'stock_products' => $stock->count(),
            'total_available_qty' => (int) $stock->sum('available_qty'),
            'total_available_points' => (int) $stock->sum('available_points'),
            'retail_traders_count' => User::query()
                ->where('role', 'retail_trader')
                ->where('parent_distributor_id', $user->id)
                ->where('is_active', true)
                ->count(),
            'distributions_sent' => InvoiceDistribution::query()
                ->where('from_user_id', $user->id)
                ->count(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }
}

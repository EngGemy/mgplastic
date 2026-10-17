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

        $receivedPoints = app(WholesalerNetworkService::class)->netPoints($user->id, 3, 'to_user_id');

        return [
            'role' => 'plumber',
            'wallet' => [
                'balance_cents' => $wallet->balance_cents,
                'balance_points' => (int) $wallet->balance_points,
                'received_points' => $receivedPoints,
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
        $panel = self::pointsPanelForRetail($user);

        return [
            'role' => 'retail_trader',
            'wallet' => (int) $panel['remaining_points'],
            'received_points' => (int) $panel['total_points'],
            'distributed_points' => (int) $panel['distributed_points'],
            'points_panel' => $panel,
            'stock_products' => (int) $panel['stock']['products'],
            'total_available_qty' => (int) $panel['stock']['units'],
            'total_available_points' => (int) $panel['stock']['points_in_stock'],
            'plumbers_count' => (int) $panel['network']['plumbers_count'],
            'distributions_sent' => InvoiceDistribution::query()
                ->where('from_user_id', $user->id)
                ->count(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forWholesaler(User $user): array
    {
        $panel = self::pointsPanelForWholesale($user);

        return [
            'role' => 'wholesale_distributor',
            'wallet' => (int) $panel['remaining_points'],
            'factory_points' => (int) $panel['total_points'],
            'distributed_points' => (int) $panel['distributed_points'],
            'points_panel' => $panel,
            'stock_products' => (int) $panel['stock']['products'],
            'total_available_qty' => (int) $panel['stock']['units'],
            'total_available_points' => (int) $panel['stock']['points_in_stock'],
            'retail_traders_count' => (int) $panel['network']['retail_traders_count'],
            'distributions_sent' => InvoiceDistribution::query()
                ->where('from_user_id', $user->id)
                ->count(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }

    /**
     * UI-ready points control panel for retail trader apps.
     *
     * @return array<string, mixed>
     */
    public static function pointsPanelForRetail(User $user): array
    {
        $summary = app(RetailTraderNetworkService::class)->getSummary($user);
        $stock = app(NetworkInventoryService::class)->stockForRetailTrader($user);
        $total = (int) ($summary['received_points'] ?? 0);
        $distributed = (int) ($summary['distributed_points'] ?? 0);
        $remaining = (int) ($summary['remaining_points'] ?? max(0, $total - $distributed));

        return self::buildPointsPanel(
            role: 'retail_trader',
            totalLabel: 'إجمالي النقاط المستلمة',
            total: $total,
            distributed: $distributed,
            remaining: $remaining,
            stock: $stock,
            network: [
                'plumbers_count' => User::query()
                    ->where('role', 'plumber')
                    ->where('parent_distributor_id', $user->id)
                    ->where('is_active', true)
                    ->count(),
            ],
        );
    }

    /**
     * UI-ready points control panel for wholesale distributor apps.
     *
     * @return array<string, mixed>
     */
    public static function pointsPanelForWholesale(User $user): array
    {
        $summary = app(WholesalerNetworkService::class)->getSummary($user);
        $stock = app(NetworkInventoryService::class)->stockForWholesaler($user);
        $total = (int) ($summary['factory_points'] ?? 0);
        $distributed = (int) ($summary['distributed_points'] ?? 0);
        $remaining = (int) ($summary['remaining_points'] ?? max(0, $total - $distributed));

        return self::buildPointsPanel(
            role: 'wholesale_distributor',
            totalLabel: 'إجمالي نقاط المصنع',
            total: $total,
            distributed: $distributed,
            remaining: $remaining,
            stock: $stock,
            network: [
                'retail_traders_count' => app(RetailNetworkLinkService::class)
                    ->linkedRetailersFor($user)
                    ->count(),
            ],
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $stock
     * @param  array<string, mixed>  $network
     * @return array<string, mixed>
     */
    protected static function buildPointsPanel(
        string $role,
        string $totalLabel,
        int $total,
        int $distributed,
        int $remaining,
        $stock,
        array $network,
    ): array {
        $percentDistributed = $total > 0 ? (int) round(($distributed / $total) * 100) : 0;
        $percentRemaining = $total > 0 ? (int) round(($remaining / $total) * 100) : 0;

        return [
            'role' => $role,
            'title' => 'لوحة تحكم النقاط',
            'subtitle' => 'ملخص النقاط والمخزون لطلبك',
            'total_points' => $total,
            'distributed_points' => $distributed,
            'remaining_points' => $remaining,
            'percent_distributed' => $percentDistributed,
            'percent_remaining' => $percentRemaining,
            'cards' => [
                [
                    'key' => 'total',
                    'label' => $totalLabel,
                    'value' => $total,
                    'color' => 'primary',
                    'icon' => 'points_total',
                ],
                [
                    'key' => 'distributed',
                    'label' => 'النقاط الموزّعة',
                    'value' => $distributed,
                    'color' => 'warning',
                    'icon' => 'points_out',
                ],
                [
                    'key' => 'remaining',
                    'label' => 'النقاط المتبقية',
                    'value' => $remaining,
                    'color' => 'success',
                    'icon' => 'points_wallet',
                ],
            ],
            'stock' => [
                'products' => $stock->count(),
                'units' => (int) $stock->sum('available_qty'),
                'points_in_stock' => (int) $stock->sum('available_points'),
            ],
            'network' => $network,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}

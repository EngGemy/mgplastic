<?php

namespace App\Services;

use App\Models\User;

class RetailTraderNetworkService
{
    public function getSummary(User $retailTrader): array
    {
        if (! $retailTrader->isRetailTrader()) {
            return [];
        }

        $stock = app(NetworkInventoryService::class)->stockForRetailTrader($retailTrader);
        $remainingPoints = (int) $stock->sum('available_points');
        $network = app(WholesalerNetworkService::class);
        $distributedPoints = $network->netPoints($retailTrader->id, 3, 'from_user_id');

        return [
            'balance_points' => $remainingPoints,
            'remaining_points' => $remainingPoints,
            'received_points' => $remainingPoints + $distributedPoints,
            'distributed_points' => $distributedPoints,
            'product_units' => (int) $stock->sum('available_qty'),
            'plumbers_count' => $retailTrader->plumbers()->count(),
        ];
    }

    public function inventoryUnits(User $retailTrader): int
    {
        return (int) app(NetworkInventoryService::class)
            ->stockForRetailTrader($retailTrader)
            ->sum('available_qty');
    }
}

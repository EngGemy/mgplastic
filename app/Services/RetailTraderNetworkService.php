<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;

class RetailTraderNetworkService
{
    public function getSummary(User $retailTrader): array
    {
        if (! $retailTrader->isRetailTrader()) {
            return [];
        }

        $stock = app(NetworkInventoryService::class)->stockForRetailTrader($retailTrader);
        $network = app(WholesalerNetworkService::class);
        $receivedPoints = (int) Invoice::query()
            ->approvedWholesale()
            ->where('invoice_flow', 'outgoing')
            ->where('counterparty_user_id', $retailTrader->id)
            ->sum('points_awarded');

        if ($receivedPoints === 0) {
            $receivedPoints = $network->netPoints($retailTrader->id, 2, 'to_user_id');
        }
        $distributedPoints = $network->netPoints($retailTrader->id, 3, 'from_user_id');
        $remainingPoints = max(0, $receivedPoints - $distributedPoints);

        return [
            'balance_points' => $remainingPoints,
            'remaining_points' => $remainingPoints,
            'received_points' => $receivedPoints,
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

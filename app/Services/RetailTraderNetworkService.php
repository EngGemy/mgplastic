<?php

namespace App\Services;

use App\Models\InvoiceDistribution;
use App\Models\User;
use App\Models\WalletAccount;

class RetailTraderNetworkService
{
    public function getSummary(User $retailTrader): array
    {
        if (! $retailTrader->isRetailTrader()) {
            return [];
        }

        $wallet = WalletAccount::firstOrCreate(
            ['owner_id' => $retailTrader->id, 'currency' => 'LYD'],
            ['balance_cents' => 0, 'balance_points' => 0]
        );

        $receivedPoints = (int) InvoiceDistribution::query()
            ->where('to_user_id', $retailTrader->id)
            ->where('tier', 2)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
            ->sum('idi.points_value');

        $distributedPoints = (int) InvoiceDistribution::query()
            ->where('from_user_id', $retailTrader->id)
            ->where('tier', 3)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
            ->sum('idi.points_value');

        return [
            'balance_points' => (int) $wallet->balance_points,
            'received_points' => $receivedPoints,
            'distributed_points' => $distributedPoints,
            'product_units' => $this->inventoryUnits($retailTrader),
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

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\User;

class WholesalerNetworkService
{
    public function getSummary(User $wholesaler): array
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            return [];
        }

        $stock = app(NetworkInventoryService::class)->stockForWholesaler($wholesaler);
        $remainingPoints = (int) $stock->sum('available_points');
        $distributedPoints = $this->netPoints($wholesaler->id, 2, 'from_user_id');

        return [
            'balance_points' => $remainingPoints,
            'remaining_points' => $remainingPoints,
            'factory_points' => $remainingPoints + $distributedPoints,
            'distributed_points' => $distributedPoints,
            'product_units' => (int) $stock->sum('available_qty'),
            'product_types' => $stock->count(),
            'retail_traders_count' => app(RetailNetworkLinkService::class)->linkedRetailersFor($wholesaler)->count(),
        ];
    }

    public function netPoints(int $userId, int $tier, string $userColumn): int
    {
        if (! in_array($userColumn, ['from_user_id', 'to_user_id'], true)) {
            return 0;
        }

        $lines = InvoiceDistribution::query()
            ->where($userColumn, $userId)
            ->where('tier', $tier)
            ->whereIn('invoice_distributions.status', ['confirmed', 'points_awarded'])
            ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
            ->get(['idi.quantity', 'idi.returned_quantity', 'idi.points_value']);

        return (int) $lines->sum(function ($line) {
            $quantity = (int) $line->quantity;
            $net = max(0, $quantity - (int) $line->returned_quantity);

            if ($quantity <= 0 || $net <= 0) {
                return 0;
            }

            return (int) floor(((int) $line->points_value) * $net / $quantity);
        });
    }

    public function inventoryUnits(User $wholesaler): int
    {
        $invoices = Invoice::query()
            ->where('wholesale_distributor_id', $wholesaler->id)
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'incoming')
            ->where('status', 'approved')
            ->with('items')
            ->get();

        $units = 0;

        foreach ($invoices as $invoice) {
            $tier1 = InvoiceDistribution::query()
                ->where('invoice_id', $invoice->id)
                ->where('tier', 1)
                ->where('to_user_id', $wholesaler->id)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->first();

            if (! $tier1) {
                continue;
            }

            foreach ($invoice->items as $item) {
                $units += $item->availableQuantityForTier(2, $tier1->id);
            }
        }

        return $units;
    }

    public function distinctProductsInStock(User $wholesaler): int
    {
        $invoices = Invoice::query()
            ->where('wholesale_distributor_id', $wholesaler->id)
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'incoming')
            ->where('status', 'approved')
            ->with('items')
            ->get();

        $productIds = [];

        foreach ($invoices as $invoice) {
            $tier1 = InvoiceDistribution::query()
                ->where('invoice_id', $invoice->id)
                ->where('tier', 1)
                ->where('to_user_id', $wholesaler->id)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->first();

            if (! $tier1) {
                continue;
            }

            foreach ($invoice->items as $item) {
                if ($item->availableQuantityForTier(2, $tier1->id) > 0) {
                    $productIds[$item->product_id] = true;
                }
            }
        }

        return count($productIds);
    }
}

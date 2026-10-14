<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Models\WalletAccount;

class WholesalerNetworkService
{
    public function getSummary(User $wholesaler): array
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            return [];
        }

        $stock = app(NetworkInventoryService::class)->stockForWholesaler($wholesaler);
        $distributedPoints = $this->netPoints($wholesaler->id, 2, 'from_user_id');
        $factoryPoints = $this->invoicePoints($wholesaler->id);
        $remainingPoints = max(0, $factoryPoints - $distributedPoints);

        return [
            'balance_points' => $remainingPoints,
            'remaining_points' => $remainingPoints,
            'factory_points' => $factoryPoints,
            'distributed_points' => $distributedPoints,
            'product_units' => (int) $stock->sum('available_qty'),
            'product_types' => $stock->count(),
            'retail_traders_count' => app(RetailNetworkLinkService::class)->linkedRetailersFor($wholesaler)->count(),
        ];
    }

    public function netPoints(?int $userId, int $tier, ?string $userColumn = null): int
    {
        $lines = InvoiceDistribution::query()
            ->where('tier', $tier)
            ->whereIn('invoice_distributions.status', ['confirmed', 'points_awarded'])
            ->when($userId, function ($query) use ($userId, $userColumn) {
                if (! in_array($userColumn, ['from_user_id', 'to_user_id'], true)) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->where($userColumn, $userId);
            })
            ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
            ->get(['idi.quantity', 'idi.returned_quantity', 'idi.points_value']);

        return (int) $lines->sum(fn ($line) => InvoiceDistributionItem::pointsFor(
            (int) $line->quantity,
            (int) $line->returned_quantity,
            (int) $line->points_value,
        ));
    }

    /** @return array{factory_points:int, distributed_points:int, remaining_points:int, plumber_points:int, plumber_balances:int} */
    public function networkTotals(): array
    {
        $factory = (int) InvoiceItem::query()
            ->whereHas('invoice', fn ($q) => $q->approvedWholesale()->where('invoice_flow', 'incoming'))
            ->sum('total_points');
        $distributed = $this->netPoints(null, 2);
        $plumberPoints = $this->netPoints(null, 3);

        return [
            'factory_points' => $factory,
            'distributed_points' => $distributed,
            'remaining_points' => max(0, $factory - $distributed),
            'plumber_points' => $plumberPoints,
            'plumber_balances' => (int) WalletAccount::query()
                ->whereHas('owner', fn ($q) => $q->where('role', 'plumber'))
                ->sum('balance_points'),
        ];
    }

    public function invoicePoints(int $wholesalerId): int
    {
        return (int) InvoiceItem::query()
            ->whereHas('invoice', fn ($q) => $q
                ->approvedWholesale()
                ->where('invoice_flow', 'incoming')
                ->where('wholesale_distributor_id', $wholesalerId))
            ->sum('total_points');
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

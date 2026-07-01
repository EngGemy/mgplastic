<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\User;
use App\Models\WalletAccount;

class WholesalerNetworkService
{
    public function __construct(
        protected WholesalerPointsSyncService $sync,
    ) {}

    public function getSummary(User $wholesaler): array
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            return [];
        }

        $this->sync->syncForWholesaler($wholesaler);

        $wallet = WalletAccount::firstOrCreate(
            ['owner_id' => $wholesaler->id, 'currency' => 'LYD'],
            ['balance_cents' => 0, 'balance_points' => 0]
        );

        $factoryPoints = (int) Invoice::query()
            ->where('wholesale_distributor_id', $wholesaler->id)
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'incoming')
            ->where('status', 'approved')
            ->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->sum('invoice_items.total_points');

        $distributedPoints = (int) InvoiceDistribution::query()
            ->where('from_user_id', $wholesaler->id)
            ->where('tier', 2)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
            ->sum('idi.points_value');

        $productUnits = $this->inventoryUnits($wholesaler);
        $productTypes = $this->distinctProductsInStock($wholesaler);

        return [
            'balance_points' => (int) $wallet->balance_points,
            'factory_points' => $factoryPoints,
            'distributed_points' => $distributedPoints,
            'product_units' => $productUnits,
            'product_types' => $productTypes,
            'retail_traders_count' => $wholesaler->retailTraders()->count(),
        ];
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

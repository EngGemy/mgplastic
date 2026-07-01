<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Collection;

class NetworkInventoryService
{
    /**
     * @return Collection<int, array{
     *   product_id:int,
     *   name:string,
     *   image:?string,
     *   category_id:?int,
     *   points_per_unit:float,
     *   available_qty:int,
     *   available_points:int,
     *   slots:array<int, array{invoice_id:int, invoice_item_id:int, parent_distribution_id:int, available_qty:int, points_per_unit:float}>
     * }>
     */
    public function stockForWholesaler(User $wholesaler): Collection
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            return collect();
        }

        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        $byProduct = [];

        $invoices = Invoice::query()
            ->where('wholesale_distributor_id', $wholesaler->id)
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'incoming')
            ->where('status', 'approved')
            ->with(['items.product.translations', 'items.product.category'])
            ->orderBy('created_at')
            ->get();

        foreach ($invoices as $invoice) {
            $tier1 = $invoice->distributions()
                ->where('tier', 1)
                ->where('to_user_id', $wholesaler->id)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->first();

            if (! $tier1) {
                continue;
            }

            foreach ($invoice->items as $item) {
                $this->appendStockSlot($byProduct, $item, $tier1->id, 2);
            }
        }

        return collect(array_values($byProduct))->sortBy('name')->values();
    }

    /**
     * @return Collection<int, array{
     *   product_id:int,
     *   name:string,
     *   image:?string,
     *   category_id:?int,
     *   points_per_unit:float,
     *   available_qty:int,
     *   available_points:int,
     *   slots:array<int, array{invoice_id:int, invoice_item_id:int, parent_distribution_id:int, available_qty:int, points_per_unit:float}>
     * }>
     */
    public function stockForRetailTrader(User $retailTrader): Collection
    {
        if (! $retailTrader->isRetailTrader()) {
            return collect();
        }

        $byProduct = [];

        $tier2Distributions = InvoiceDistribution::query()
            ->where('to_user_id', $retailTrader->id)
            ->where('tier', 2)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->with(['invoice.items.product.translations', 'invoice.items.product.category'])
            ->orderBy('confirmed_at')
            ->get();

        foreach ($tier2Distributions as $distribution) {
            if (! $distribution->invoice) {
                continue;
            }

            foreach ($distribution->invoice->items as $item) {
                $this->appendStockSlot($byProduct, $item, $distribution->id, 3);
            }
        }

        return collect(array_values($byProduct))->sortBy('name')->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $byProduct
     */
    private function appendStockSlot(array &$byProduct, InvoiceItem $item, int $parentDistributionId, int $tier): void
    {
        $available = $item->availableQuantityForTier($tier, $parentDistributionId);

        if ($available <= 0) {
            return;
        }

        $product = $item->product;
        $productId = (int) $item->product_id;
        $pointsPerUnit = (float) $item->points_per_unit;

        if (! isset($byProduct[$productId])) {
            $byProduct[$productId] = [
                'product_id' => $productId,
                'name' => localized_name($product, 'name', "منتج #{$productId}"),
                'image' => $product?->main_image,
                'category_id' => $product?->product_category_id,
                'points_per_unit' => $pointsPerUnit,
                'available_qty' => 0,
                'available_points' => 0,
                'slots' => [],
            ];
        }

        $byProduct[$productId]['available_qty'] += $available;
        $byProduct[$productId]['available_points'] += (int) floor($available * $pointsPerUnit);
        $byProduct[$productId]['slots'][] = [
            'invoice_id' => (int) $item->invoice_id,
            'invoice_item_id' => (int) $item->id,
            'parent_distribution_id' => $parentDistributionId,
            'available_qty' => $available,
            'points_per_unit' => $pointsPerUnit,
        ];
    }

    /**
     * @param  array<int, int>  $requestedByProduct  product_id => quantity
     * @return array<int, array{invoice_id:int, parent_distribution_id:int, items:array<int, array{invoice_item_id:int, quantity:int}>}>
     */
    public function allocateFromStock(Collection $stock, array $requestedByProduct): array
    {
        $stockByProduct = $stock->keyBy('product_id');
        $groups = [];

        foreach ($requestedByProduct as $productId => $requestedQty) {
            $requestedQty = (int) $requestedQty;

            if ($requestedQty <= 0) {
                throw new \DomainException('الكمية يجب أن تكون أكبر من صفر');
            }

            $row = $stockByProduct->get($productId);

            if (! $row) {
                $name = Product::with('translations')->find($productId);
                $label = $name ? localized_name($name, 'name', 'منتج') : 'منتج';

                throw new \DomainException("المنتج «{$label}» غير متوفر في مخزونك");
            }

            if ($requestedQty > (int) $row['available_qty']) {
                throw new \DomainException(
                    "الكمية المطلوبة من «{$row['name']}» ({$requestedQty}) تتجاوز المتوفر في المخزن ({$row['available_qty']})"
                );
            }

            $remaining = $requestedQty;

            foreach ($row['slots'] as $slot) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, (int) $slot['available_qty']);

                if ($take <= 0) {
                    continue;
                }

                $groupKey = $slot['invoice_id'].':'.$slot['parent_distribution_id'];

                if (! isset($groups[$groupKey])) {
                    $groups[$groupKey] = [
                        'invoice_id' => (int) $slot['invoice_id'],
                        'parent_distribution_id' => (int) $slot['parent_distribution_id'],
                        'items' => [],
                    ];
                }

                $groups[$groupKey]['items'][] = [
                    'invoice_item_id' => (int) $slot['invoice_item_id'],
                    'quantity' => $take,
                ];

                $remaining -= $take;
            }

            if ($remaining > 0) {
                throw new \DomainException("تعذّر تخصيص الكمية الكاملة للمنتج «{$row['name']}» من المخزون");
            }
        }

        return array_values($groups);
    }

    public function walletBalance(User $user): int
    {
        return (int) (WalletAccount::query()
            ->where('owner_id', $user->id)
            ->where('currency', 'LYD')
            ->value('balance_points') ?? 0);
    }

    /**
     * @param  array<int, int>  $requestedByProduct
     */
    public function totalPointsForRequest(Collection $stock, array $requestedByProduct): int
    {
        $stockByProduct = $stock->keyBy('product_id');
        $total = 0;

        foreach ($requestedByProduct as $productId => $qty) {
            $row = $stockByProduct->get($productId);
            if (! $row) {
                continue;
            }

            $total += (int) floor((int) $qty * (float) $row['points_per_unit']);
        }

        return $total;
    }

    public function assertPointsBalance(User $seller, int $requiredPoints): void
    {
        if ($requiredPoints <= 0) {
            return;
        }

        $balance = $this->walletBalance($seller);

        if ($requiredPoints > $balance) {
            throw new \DomainException(
                "رصيد النقاط غير كافٍ: المطلوب {$requiredPoints} نقطة، رصيدك {$balance} نقطة"
            );
        }
    }
}

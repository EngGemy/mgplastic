<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

        $cacheKey = 'net_inv:wholesaler:'.$wholesaler->id;

        return Cache::store('array')->remember($cacheKey, 120, function () use ($wholesaler) {
            app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

            $byProduct = [];

            $invoices = Invoice::query()
                ->where('wholesale_distributor_id', $wholesaler->id)
                ->where('invoice_type', 'wholesale_pos')
                ->where('invoice_flow', 'incoming')
                ->where('status', 'approved')
                ->with([
                    'items.product.translations',
                    'items.product.category',
                    'items.product.tags',
                    'distributions' => fn ($q) => $q
                        ->where('tier', 1)
                        ->where('to_user_id', $wholesaler->id)
                        ->whereIn('status', ['confirmed', 'points_awarded']),
                ])
                ->orderBy('created_at')
                ->get();

            $pairs = [];
            foreach ($invoices as $invoice) {
                $tier1 = $invoice->distributions->first();
                if (! $tier1) {
                    continue;
                }
                foreach ($invoice->items as $item) {
                    $pairs[] = [(int) $item->id, (int) $tier1->id, $item];
                }
            }

            $availableByPair = $this->bulkAvailableForTier(
                collect($pairs)->map(fn ($p) => ['invoice_item_id' => $p[0], 'parent_distribution_id' => $p[1]])->all(),
                tier: 2,
            );

            foreach ($pairs as [$itemId, $parentId, $item]) {
                $available = (int) ($availableByPair["{$itemId}:{$parentId}"] ?? 0);
                $this->appendStockSlot($byProduct, $item, $parentId, 2, $available);
            }

            return $this->sortStockByTag(collect(array_values($byProduct)));
        });
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
            ->with([
                'items.invoiceItem.product.translations',
                'items.invoiceItem.product.category',
                'items.invoiceItem.product.tags',
            ])
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->get();

        foreach ($tier2Distributions as $distribution) {
            foreach ($distribution->items as $distItem) {
                $item = $distItem->invoiceItem;

                if (! $item) {
                    continue;
                }

                $this->appendStockSlot($byProduct, $item, (int) $distribution->id, 3);
            }
        }

        return $this->sortStockByTag(collect(array_values($byProduct)));
    }

    /**
     * Prefetch available qty for many (invoice_item_id, parent_distribution_id) pairs.
     *
     * @param  list<array{invoice_item_id:int, parent_distribution_id:int}>  $pairs
     * @return array<string, int> key "{invoice_item_id}:{parent_distribution_id}"
     */
    private function bulkAvailableForTier(array $pairs, int $tier): array
    {
        if ($pairs === [] || $tier < 2) {
            return [];
        }

        $itemIds = array_values(array_unique(array_map(fn ($p) => (int) $p['invoice_item_id'], $pairs)));
        $parentIds = array_values(array_unique(array_map(fn ($p) => (int) $p['parent_distribution_id'], $pairs)));

        $parentNets = InvoiceDistributionItem::query()
            ->whereIn('distribution_id', $parentIds)
            ->whereIn('invoice_item_id', $itemIds)
            ->get(['distribution_id', 'invoice_item_id', 'quantity', 'returned_quantity'])
            ->keyBy(fn ($row) => $row->invoice_item_id.':'.$row->distribution_id);

        $confirmed = InvoiceDistributionItem::query()
            ->selectRaw('invoice_distribution_items.invoice_item_id, invoice_distributions.parent_distribution_id, SUM(invoice_distribution_items.quantity - COALESCE(invoice_distribution_items.returned_quantity, 0)) as confirmed_qty')
            ->join('invoice_distributions', 'invoice_distributions.id', '=', 'invoice_distribution_items.distribution_id')
            ->where('invoice_distributions.tier', $tier)
            ->whereIn('invoice_distributions.parent_distribution_id', $parentIds)
            ->whereIn('invoice_distributions.status', ['confirmed', 'points_awarded'])
            ->whereIn('invoice_distribution_items.invoice_item_id', $itemIds)
            ->groupBy('invoice_distribution_items.invoice_item_id', 'invoice_distributions.parent_distribution_id')
            ->get()
            ->keyBy(fn ($row) => $row->invoice_item_id.':'.$row->parent_distribution_id);

        $out = [];
        foreach ($pairs as $pair) {
            $itemId = (int) $pair['invoice_item_id'];
            $parentId = (int) $pair['parent_distribution_id'];
            $key = "{$itemId}:{$parentId}";
            $parent = $parentNets->get($key);
            if (! $parent) {
                $out[$key] = 0;
                continue;
            }
            $parentNet = (int) $parent->quantity - (int) ($parent->returned_quantity ?? 0);
            $already = (int) ($confirmed->get($key)?->confirmed_qty ?? 0);
            $out[$key] = max(0, $parentNet - $already);
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $byProduct
     */
    private function appendStockSlot(array &$byProduct, InvoiceItem $item, int $parentDistributionId, int $tier, ?int $availableOverride = null): void
    {
        $available = $availableOverride ?? $item->availableQuantityForTier($tier, $parentDistributionId);

        if ($available <= 0) {
            return;
        }

        $product = $item->product;
        $productId = (int) $item->product_id;
        $pointsPerUnit = (float) $item->points_per_unit;

        if (! isset($byProduct[$productId])) {
            $group = $this->resolveStockGroup($product);

            $byProduct[$productId] = [
                'product_id' => $productId,
                'name' => localized_name($product, 'name', "منتج #{$productId}"),
                'image' => $product?->main_image,
                'category_id' => $product?->product_category_id,
                'tags' => $group['tags'],
                'tag' => $group['tag'],
                'group_key' => $group['key'],
                'group_name' => $group['name'],
                'product_type' => $group['product_type'],
                'product_type_name' => $group['product_type_name'],
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

    /**
     * Sort POS stock so same tag / piece-type sit together (أكواع، تيهات، …).
     *
     * @param  Collection<int, array<string, mixed>>  $stock
     * @return Collection<int, array<string, mixed>>
     */
    public function sortStockByTag(Collection $stock): Collection
    {
        return $stock
            ->sortBy([
                fn (array $row) => mb_strtolower((string) ($row['group_name'] ?? $row['tag'] ?? 'ىىى')),
                fn (array $row) => mb_strtolower((string) ($row['name'] ?? '')),
            ])
            ->values();
    }

    /**
     * Group flat stock rows for POS UIs / mobile sections.
     *
     * @param  Collection<int, array<string, mixed>>  $stock
     * @return list<array{key:string, tag:string, name:string, product_type:?string, items:list<array<string, mixed>>}>
     */
    public function groupStockByTag(Collection $stock): array
    {
        $grouped = [];

        foreach ($this->sortStockByTag($stock) as $row) {
            $key = (string) ($row['group_key'] ?? 'other');
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'key' => $key,
                    'tag' => (string) ($row['tag'] ?? $row['group_name'] ?? 'أخرى'),
                    'name' => (string) ($row['group_name'] ?? $row['tag'] ?? 'أخرى'),
                    'product_type' => $row['product_type'] ?? null,
                    'items' => [],
                ];
            }
            $grouped[$key]['items'][] = $row;
        }

        return array_values($grouped);
    }

    /**
     * @return array{
     *   tags: list<string>,
     *   tag: string,
     *   key: string,
     *   name: string,
     *   product_type: ?string,
     *   product_type_name: ?string
     * }
     */
    protected function resolveStockGroup(?Product $product): array
    {
        $tags = [];
        if ($product && $product->relationLoaded('tags')) {
            $tags = $product->tags->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->values()->all();
        }

        $type = null;
        if ($product) {
            if (! $product->relationLoaded('tags')) {
                $product->loadMissing('tags', 'translations');
                $tags = $product->tags->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->values()->all();
            }
            $type = app(ProductTypeService::class)->resolveForProduct($product);
        }

        // Prefer an actual product tag that matches a known piece-type, else first tag, else type, else أخرى.
        $primaryTag = null;
        if ($type) {
            foreach ($tags as $tagName) {
                $hay = mb_strtolower($tagName);
                $def = app(ProductTypeService::class)->findDefinition($type['key']);
                foreach ($def['keywords'] ?? [] as $kw) {
                    if ($kw !== '' && Str::contains($hay, mb_strtolower($kw))) {
                        $primaryTag = $tagName;
                        break 2;
                    }
                }
            }
        }
        $primaryTag = $primaryTag ?? ($tags[0] ?? null);

        if ($primaryTag) {
            return [
                'tags' => $tags,
                'tag' => $primaryTag,
                'key' => $type['key'] ?? ('tag:'.Str::slug($primaryTag, '_')),
                'name' => $type['name'] ?? $primaryTag,
                'product_type' => $type['key'] ?? null,
                'product_type_name' => $type['name'] ?? null,
            ];
        }

        if ($type) {
            return [
                'tags' => $tags,
                'tag' => $type['name'],
                'key' => $type['key'],
                'name' => $type['name'],
                'product_type' => $type['key'],
                'product_type_name' => $type['name'],
            ];
        }

        return [
            'tags' => $tags,
            'tag' => 'أخرى',
            'key' => 'other',
            'name' => 'أخرى',
            'product_type' => null,
            'product_type_name' => null,
        ];
    }
}

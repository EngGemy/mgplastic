<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletAccount;
use App\Support\ProductSizeSorter;
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
    public function forgetWholesalerStockCache(int|User $wholesaler): void
    {
        $id = $wholesaler instanceof User ? (int) $wholesaler->id : (int) $wholesaler;
        Cache::store('array')->forget('net_inv:wholesaler:'.$id);
    }

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
                    'items.product.category.translations',
                    'items.product.category.parent.translations',
                    'items.product.tags',
                    'items.product.sizes',
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
                'items.invoiceItem.product.category.translations',
                'items.invoiceItem.product.category.parent.translations',
                'items.invoiceItem.product.tags',
                'items.invoiceItem.product.sizes',
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
            // Include draft: reserved until the buyer confirms receipt.
            ->whereIn('invoice_distributions.status', ['draft', 'confirmed', 'points_awarded'])
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
        // Sale display/preview = live catalog only (incl. 0). Receive rate kept as metadata.
        $receivePpu = (float) $item->points_per_unit;
        $pointsPerUnit = app(\App\Services\Points\CatalogPointsPolicy::class)
            ->catalogRate($product, null);

        if (! isset($byProduct[$productId])) {
            $group = $this->resolveStockGroup($product);
            $category = $product?->relationLoaded('category') ? $product->category : $product?->category;
            if ($category && ! $category->relationLoaded('translations')) {
                $category->loadMissing('translations', 'parent.translations');
            }

            $size = ProductSizeSorter::resolve(
                $product,
                implode(' ', array_filter([
                    $group['tag'] ?? null,
                    $group['name'] ?? null,
                    ...($group['tags'] ?? []),
                ])),
            );

            $piecesPerBox = (int) ($product?->pieces_per_box ?? 0);

            $byProduct[$productId] = [
                'product_id' => $productId,
                'name' => localized_name($product, 'name', "منتج #{$productId}"),
                'image' => $product?->main_image,
                'category_id' => $product?->product_category_id,
                'category_name' => $category
                    ? localized_name($category, 'name', $category->slug ?? 'تصنيف')
                    : 'بدون تصنيف',
                'is_accessories' => (bool) ($category?->isAccessoriesFamily()),
                'tags' => $group['tags'],
                'tag' => $group['tag'],
                'group_key' => $group['key'],
                'group_name' => $group['name'],
                'product_type' => $group['product_type'],
                'product_type_name' => $group['product_type_name'],
                'size_value' => $size['size_value'],
                'size_label' => $size['size_label'],
                'size_unit' => $size['size_unit'],
                'size_sort' => $size['size_sort'],
                'pieces_per_box' => $piecesPerBox > 0 ? $piecesPerBox : null,
                'order_unit' => $piecesPerBox > 0 ? 'box' : 'piece',
                'points_per_unit' => $pointsPerUnit,
                'available_qty' => 0,
                'available_points' => 0,
                'slots' => [],
            ];
        }

        $byProduct[$productId]['available_qty'] += $available;
        $byProduct[$productId]['available_points'] += (int) floor($available * $pointsPerUnit);
        // Keep product-level PPU on the live catalog rate if it changes mid-aggregation.
        $byProduct[$productId]['points_per_unit'] = $pointsPerUnit;
        $byProduct[$productId]['slots'][] = [
            'invoice_id' => (int) $item->invoice_id,
            'invoice_item_id' => (int) $item->id,
            'parent_distribution_id' => $parentDistributionId,
            'available_qty' => $available,
            'points_per_unit' => $pointsPerUnit,
            'receive_points_per_unit' => $receivePpu,
        ];
    }

    /**
     * Remaining sellable qty across the network for one catalog product
     * (wholesalers + retail traders), with per-trader breakdown.
     *
     * @return array{
     *   wholesale_remaining_qty:int,
     *   retail_remaining_qty:int,
     *   wholesale_holders:int,
     *   retail_holders:int,
     *   wholesale_by_trader:list<array{user_id:int, name:string, qty:int}>,
     *   retail_by_trader:list<array{user_id:int, name:string, qty:int}>
     * }
     */
    public function networkRemainingForProduct(int $productId): array
    {
        $wholesaleQtyByUser = [];
        $retailQtyByUser = [];

        $invoices = Invoice::query()
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'incoming')
            ->where('status', 'approved')
            ->whereHas('items', fn ($q) => $q->where('product_id', $productId))
            ->with([
                'items' => fn ($q) => $q->where('product_id', $productId),
                'distributions' => fn ($q) => $q
                    ->where('tier', 1)
                    ->whereIn('status', ['confirmed', 'points_awarded']),
            ])
            ->get();

        $pairs = [];
        $pairMeta = [];
        foreach ($invoices as $invoice) {
            $tier1 = $invoice->distributions->first();
            if (! $tier1) {
                continue;
            }
            $holderId = (int) ($invoice->wholesale_distributor_id ?? $tier1->to_user_id);
            foreach ($invoice->items as $item) {
                $key = (int) $item->id.':'.(int) $tier1->id;
                $pairs[] = [
                    'invoice_item_id' => (int) $item->id,
                    'parent_distribution_id' => (int) $tier1->id,
                ];
                $pairMeta[$key] = $holderId;
            }
        }

        $availableByPair = $this->bulkAvailableForTier($pairs, tier: 2);
        foreach ($availableByPair as $key => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $holderId = (int) ($pairMeta[$key] ?? 0);
            if ($holderId > 0) {
                $wholesaleQtyByUser[$holderId] = ($wholesaleQtyByUser[$holderId] ?? 0) + $qty;
            }
        }

        $tier2 = InvoiceDistribution::query()
            ->where('tier', 2)
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->whereHas('items.invoiceItem', fn ($q) => $q->where('product_id', $productId))
            ->with([
                'items' => fn ($q) => $q->whereHas('invoiceItem', fn ($iq) => $iq->where('product_id', $productId)),
                'items.invoiceItem',
            ])
            ->get();

        $retailPairs = [];
        $retailMeta = [];
        foreach ($tier2 as $distribution) {
            $holderId = (int) $distribution->to_user_id;
            foreach ($distribution->items as $distItem) {
                $item = $distItem->invoiceItem;
                if (! $item || (int) $item->product_id !== $productId) {
                    continue;
                }
                $key = (int) $item->id.':'.(int) $distribution->id;
                $retailPairs[] = [
                    'invoice_item_id' => (int) $item->id,
                    'parent_distribution_id' => (int) $distribution->id,
                ];
                $retailMeta[$key] = $holderId;
            }
        }

        $retailAvailable = $this->bulkAvailableForTier($retailPairs, tier: 3);
        foreach ($retailAvailable as $key => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $holderId = (int) ($retailMeta[$key] ?? 0);
            if ($holderId > 0) {
                $retailQtyByUser[$holderId] = ($retailQtyByUser[$holderId] ?? 0) + $qty;
            }
        }

        $userIds = array_values(array_unique(array_merge(
            array_keys($wholesaleQtyByUser),
            array_keys($retailQtyByUser),
        )));
        $names = $userIds === []
            ? []
            : User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all();

        $mapHolders = function (array $qtyByUser) use ($names): array {
            $rows = [];
            foreach ($qtyByUser as $userId => $qty) {
                $rows[] = [
                    'user_id' => (int) $userId,
                    'name' => (string) ($names[$userId] ?? ('تاجر #'.$userId)),
                    'qty' => (int) $qty,
                ];
            }
            usort($rows, fn ($a, $b) => $b['qty'] <=> $a['qty']);

            return $rows;
        };

        $wholesaleByTrader = $mapHolders($wholesaleQtyByUser);
        $retailByTrader = $mapHolders($retailQtyByUser);

        return [
            'wholesale_remaining_qty' => array_sum($wholesaleQtyByUser),
            'retail_remaining_qty' => array_sum($retailQtyByUser),
            'wholesale_holders' => count($wholesaleQtyByUser),
            'retail_holders' => count($retailQtyByUser),
            'wholesale_by_trader' => $wholesaleByTrader,
            'retail_by_trader' => $retailByTrader,
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
     * Sort POS stock: category → tag size (1 → 1.5) → product size → name.
     *
     * @param  Collection<int, array<string, mixed>>  $stock
     * @return Collection<int, array<string, mixed>>
     */
    public function sortStockByTag(Collection $stock, string $sizeOrder = 'asc'): Collection
    {
        $sizeOrder = ProductSizeSorter::normalizeOrder($sizeOrder);

        return $stock
            ->sort(fn (array $a, array $b) => ProductSizeSorter::compareRows($a, $b, $sizeOrder))
            ->values();
    }

    /**
     * Flat tag groups for mobile accordion.
     * One section per product tag; ordered by the number inside the tag
     * («1 بوصه» → «1.5 بوصه» → «3 بوصه» → «6 بوصه» → «110 مم»).
     *
     * @param  Collection<int, array<string, mixed>>  $stock
     * @return list<array{key:string, tag:string, name:string, product_type:?string, size_value:?float, items_count:int, items:list<array<string, mixed>>}>
     */
    public function groupStockByTag(Collection $stock, string $sizeOrder = 'asc'): array
    {
        $sizeOrder = ProductSizeSorter::normalizeOrder($sizeOrder);
        $grouped = [];

        foreach ($this->sortStockByTag($stock, $sizeOrder) as $row) {
            // Group by the actual tag text (not piece-type key) — tags already carry the size.
            $tagName = trim((string) ($row['tag'] ?? $row['group_name'] ?? 'أخرى'));
            if ($tagName === '') {
                $tagName = 'أخرى';
            }
            $key = 'tag:'.mb_strtolower($tagName);

            if (! isset($grouped[$key])) {
                $parsed = ProductSizeSorter::parseDetailed($tagName);
                $grouped[$key] = [
                    'key' => $key,
                    'tag' => $tagName,
                    'name' => $tagName,
                    'product_type' => $row['product_type'] ?? null,
                    'size_value' => $parsed['value'] ?? null,
                    'size_unit' => $parsed['unit'] ?? null,
                    'size_label' => $parsed['label'] ?? $tagName,
                    'size_sort' => $parsed['sort'] ?? null,
                    'items_count' => 0,
                    'items' => [],
                ];
            }
            $grouped[$key]['items'][] = $row;
            $grouped[$key]['items_count']++;
        }

        return ProductSizeSorter::sortTagGroupsByNumber(array_values($grouped), $sizeOrder);
    }

    /**
     * Alias: same as groupStockByTag (sections = tags, sorted by number in tag).
     *
     * @param  Collection<int, array<string, mixed>>  $stock
     * @return list<array<string, mixed>>
     */
    public function groupStockBySize(Collection $stock, string $sizeOrder = 'asc'): array
    {
        return $this->groupStockByTag($stock, $sizeOrder);
    }

    /**
     * Accessories browse tree: Category → Tag → products (size-ordered).
     *
     * @param  Collection<int, array<string, mixed>>  $stock
     * @return list<array{
     *   category_id:?int,
     *   category_name:string,
     *   is_accessories:bool,
     *   items_count:int,
     *   tags: list<array{key:string, tag:string, name:string, product_type:?string, size_value:?float, items_count:int, items:list<array<string, mixed>>}>
     * }>
     */
    public function groupStockByCategoryThenTag(Collection $stock, string $sizeOrder = 'asc'): array
    {
        $sizeOrder = ProductSizeSorter::normalizeOrder($sizeOrder);
        $byCategory = [];

        foreach ($this->sortStockByTag($stock, $sizeOrder) as $row) {
            $catId = (int) ($row['category_id'] ?? 0);
            $catKey = $catId > 0 ? (string) $catId : 'none';

            if (! isset($byCategory[$catKey])) {
                $byCategory[$catKey] = [
                    'category_id' => $catId > 0 ? $catId : null,
                    'category_name' => (string) ($row['category_name'] ?? 'بدون تصنيف'),
                    'is_accessories' => (bool) ($row['is_accessories'] ?? false),
                    'items_count' => 0,
                    'tags' => [],
                ];
            }

            $tagName = trim((string) ($row['tag'] ?? $row['group_name'] ?? 'أخرى'));
            if ($tagName === '') {
                $tagName = 'أخرى';
            }
            $tagKey = 'tag:'.mb_strtolower($tagName);

            if (! isset($byCategory[$catKey]['tags'][$tagKey])) {
                $parsed = ProductSizeSorter::parseDetailed($tagName);
                $byCategory[$catKey]['tags'][$tagKey] = [
                    'key' => $tagKey,
                    'tag' => $tagName,
                    'name' => $tagName,
                    'product_type' => $row['product_type'] ?? null,
                    'size_value' => $parsed['value'] ?? null,
                    'size_unit' => $parsed['unit'] ?? null,
                    'size_label' => $parsed['label'] ?? $tagName,
                    'size_sort' => $parsed['sort'] ?? null,
                    'items_count' => 0,
                    'items' => [],
                ];
            }

            $byCategory[$catKey]['tags'][$tagKey]['items'][] = $row;
            $byCategory[$catKey]['tags'][$tagKey]['items_count']++;
            $byCategory[$catKey]['items_count']++;
        }

        // Prefer accessories categories first, then alpha.
        $list = array_values($byCategory);
        usort($list, function (array $a, array $b) {
            $acc = ((int) $b['is_accessories']) <=> ((int) $a['is_accessories']);
            if ($acc !== 0) {
                return $acc;
            }

            return strcmp(
                mb_strtolower($a['category_name']),
                mb_strtolower($b['category_name']),
            );
        });

        foreach ($list as &$cat) {
            // Sort tags by the number written in the tag name.
            $cat['tags'] = ProductSizeSorter::sortTagGroupsByNumber(array_values($cat['tags']), $sizeOrder);
            $cat['size_groups'] = $cat['tags'];
        }
        unset($cat);

        return $list;
    }

    /**
     * Display group for POS/catalog: exact tag (e.g. «كوع 1 بوصة»), not piece-type bucket.
     *
     * @return array{
     *   tags: list<string>,
     *   tag: string,
     *   key: string,
     *   name: string,
     *   product_type: ?string,
     *   product_type_name: ?string
     * }
     */
    public function resolveProductGroup(?Product $product): array
    {
        return $this->resolveStockGroup($product);
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

        // Prefer a tag that carries a size number («1 بوصه» / «110 مم») — used for accordion order.
        $primaryTag = null;
        foreach ($tags as $tagName) {
            if (ProductSizeSorter::parseDetailed($tagName) !== null) {
                $primaryTag = $tagName;
                break;
            }
        }

        // Else prefer a tag that matches the resolved piece-type keywords, else first tag.
        if ($primaryTag === null && $type) {
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
                // Stable key from tag text — do NOT Str::slug Arabic (collapses every tag to "tag:").
                'key' => 'tag:'.mb_strtolower($primaryTag),
                'name' => $primaryTag,
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

    /**
     * Normalize flat catalog rows then build tag + category→tag trees for mobile.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{groups: list<array<string, mixed>>, categories: list<array<string, mixed>>}
     */
    public function groupCatalogBrowse(Collection $items, string $sizeOrder = 'asc'): array
    {
        $sizeOrder = ProductSizeSorter::normalizeOrder($sizeOrder);

        $normalized = $items->map(function (array $row) {
            $row['product_id'] = (int) ($row['product_id'] ?? $row['id'] ?? 0);
            $row['category_id'] = isset($row['category_id']) ? (int) $row['category_id'] : null;
            $row['category_name'] = (string) ($row['category_name']
                ?? $row['parent_category_name']
                ?? 'بدون تصنيف');
            $row['is_accessories'] = (bool) ($row['is_accessories']
                ?? $row['is_accessory']
                ?? false);
            $row['group_key'] = (string) ($row['group_key'] ?? 'other');
            $row['group_name'] = (string) ($row['group_name'] ?? $row['tag'] ?? 'أخرى');
            $row['tag'] = (string) ($row['tag'] ?? $row['group_name'] ?? 'أخرى');
            if (! array_key_exists('size_value', $row) || $row['size_value'] === null) {
                $size = ProductSizeSorter::resolve(null, implode(' ', array_filter([
                    $row['group_name'] ?? null,
                    $row['tag'] ?? null,
                    $row['name'] ?? null,
                ])));
                $row['size_value'] = $size['size_value'];
                $row['size_label'] = $row['size_label'] ?? $size['size_label'];
                $row['size_unit'] = $row['size_unit'] ?? $size['size_unit'];
                $row['size_sort'] = $size['size_sort'];
            }

            return $row;
        });

        return [
            'groups' => $this->groupStockByTag($normalized, $sizeOrder),
            'size_groups' => $this->groupStockBySize($normalized, $sizeOrder),
            'categories' => $this->groupStockByCategoryThenTag($normalized, $sizeOrder),
        ];
    }
}

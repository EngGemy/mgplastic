<?php

namespace App\Filament\Trader\Pages;

use App\Filament\Concerns\NotifiesPosStockLimit;
use App\Filament\Concerns\SetsCartQuantity;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\OrderService;
use App\Services\PlumberDistributionPosService;
use App\Support\OrderStatus;
use App\Support\StorePermissions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class TraderPos extends Page
{
    use NotifiesPosStockLimit;
    use SetsCartQuantity;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'كاشير البيع للسباك';

    protected static ?string $title = 'بيع للسباك — توزيع النقاط';

    protected static ?string $navigationGroup = 'نظام النقاط';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.trader.pages.trader-pos';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && $user->isRetailTrader()
            && $user->canStorePermission(StorePermissions::POS_SELL);
    }

    public ?int $plumberId = null;

    /** Wholesale distributor when ordering from الجملة. */
    public ?int $supplierId = null;

    public ?int $selectedCategoryId = null;

    /** all | available | order */
    public string $stockFilter = 'available';

    public string $search = '';

    public string $plumberSearch = '';

    public string $supplierSearch = '';

    public string $categorySearch = '';

    /** @var array<string, array<string, mixed>> */
    public array $cart = [];

    public ?int $qtyModalProductId = null;

    public int $qtyModalAmount = 1;

    protected ?Collection $catalogCache = null;

    public function getPlumbersProperty(): Collection
    {
        $query = User::query()
            ->where('role', 'plumber')
            ->where('is_active', true)
            ->visibleToRetailTrader((int) auth()->id())
            ->orderBy('name');

        $term = trim($this->plumberSearch);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('network_code', 'like', $like);
            });
        }

        return $query->get(['id', 'name', 'phone', 'network_code']);
    }

    public function getPlumbersCountProperty(): int
    {
        return User::query()
            ->where('role', 'plumber')
            ->where('is_active', true)
            ->visibleToRetailTrader((int) auth()->id())
            ->count();
    }

    public function getSelectedPlumberProperty(): ?User
    {
        if (! $this->plumberId) {
            return null;
        }

        return User::query()
            ->where('role', 'plumber')
            ->visibleToRetailTrader((int) auth()->id())
            ->whereKey($this->plumberId)
            ->first(['id', 'name', 'phone', 'network_code']);
    }

    public function selectPlumber(int $id): void
    {
        $exists = User::query()
            ->where('role', 'plumber')
            ->where('is_active', true)
            ->visibleToRetailTrader((int) auth()->id())
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            Notification::make()->danger()->title('سباك غير صحيح')->send();

            return;
        }

        $this->plumberId = $id;
        $this->plumberSearch = '';
    }

    public function clearPlumber(): void
    {
        $this->plumberId = null;
        $this->plumberSearch = '';
    }

    public function isWholesaleOrderMode(): bool
    {
        return $this->stockFilter === 'order';
    }

    public function getWholesalersProperty(): Collection
    {
        $query = User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->orderBy('brand_name')
            ->orderBy('name');

        $term = trim($this->supplierSearch);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('brand_name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('network_code', 'like', $like);
            });
        }

        return $query->get(['id', 'name', 'brand_name', 'phone', 'network_code']);
    }

    public function getSelectedWholesalerProperty(): ?User
    {
        if (! $this->supplierId) {
            return null;
        }

        return User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->whereKey($this->supplierId)
            ->first(['id', 'name', 'brand_name', 'phone', 'network_code']);
    }

    public function selectWholesaler(int $id): void
    {
        $exists = User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            Notification::make()->danger()->title('موزّع جملة غير صحيح')->send();

            return;
        }

        $this->supplierId = $id;
        $this->supplierSearch = '';
    }

    public function clearWholesaler(): void
    {
        $this->supplierId = null;
        $this->supplierSearch = '';
    }

    public function getCategoriesProperty(): Collection
    {
        $rows = $this->catalogRows();
        $productIds = $rows->pluck('product_id')->all();

        if ($productIds === []) {
            return collect();
        }

        $cats = ProductCategory::query()
            ->with(['translations', 'children:id,parent_id'])
            ->whereNull('parent_id')
            ->where(function ($q) use ($productIds) {
                $q->whereHas('products', fn ($p) => $p->whereIn('id', $productIds))
                    ->orWhereHas('children.products', fn ($p) => $p->whereIn('id', $productIds));
            })
            ->orderBy('id')
            ->get();

        $term = mb_strtolower(trim($this->categorySearch));

        return $cats
            ->map(function (ProductCategory $cat) use ($rows) {
                $allowed = collect([$cat->id])
                    ->merge($cat->children->pluck('id'))
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $inCat = $rows->filter(fn ($r) => in_array((int) ($r['category_id'] ?? 0), $allowed, true));

                return [
                    'id' => $cat->id,
                    'name' => localized_name($cat, 'name', $cat->slug ?? "فئة #{$cat->id}"),
                    'total' => $inCat->count(),
                    'available' => $inCat->where('can_distribute', true)->count(),
                ];
            })
            ->when($term !== '', fn ($c) => $c->filter(
                fn ($row) => str_contains(mb_strtolower($row['name']), $term)
            ))
            ->values();
    }

    public function getFilterCountsProperty(): array
    {
        $rows = $this->catalogRows();

        return [
            'all' => $rows->count(),
            'available' => $rows->where('can_distribute', true)->count(),
            'order' => $rows->where('can_distribute', false)->count(),
        ];
    }

    public function setStockFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'available', 'order'], true)) {
            return;
        }

        $wasOrder = $this->isWholesaleOrderMode();
        $this->stockFilter = $filter;
        $nowOrder = $this->isWholesaleOrderMode();

        if ($wasOrder !== $nowOrder) {
            $this->cart = [];
            $this->closeQtyModal();
        }

        if ($nowOrder) {
            $this->plumberId = null;
            $this->plumberSearch = '';
            $this->prefillWholesalerIfNeeded();
        } else {
            $this->supplierId = null;
            $this->supplierSearch = '';
        }
    }

    protected function prefillWholesalerIfNeeded(): void
    {
        if ($this->supplierId) {
            return;
        }

        $user = auth()->user();
        $preferred = (int) ($user?->parent_distributor_id ?? 0);
        if ($preferred > 0) {
            $ok = User::query()
                ->where('role', 'wholesale_distributor')
                ->where('is_approved', true)
                ->where('is_active', true)
                ->whereKey($preferred)
                ->exists();
            if ($ok) {
                $this->supplierId = $preferred;

                return;
            }
        }

        $only = User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->limit(2)
            ->pluck('id');

        if ($only->count() === 1) {
            $this->supplierId = (int) $only->first();
        }
    }

    public function clearFilters(): void
    {
        $this->setStockFilter('all');
        $this->selectedCategoryId = null;
        $this->search = '';
        $this->categorySearch = '';
    }

    public function getCatalogAllProperty(): Collection
    {
        return $this->catalogRows();
    }

    /**
     * All catalog products merged with network stock (points inventory).
     */
    protected function catalogRows(): Collection
    {
        if ($this->catalogCache !== null) {
            return $this->catalogCache;
        }

        $stockByProduct = app(NetworkInventoryService::class)
            ->stockForRetailTrader(auth()->user())
            ->keyBy('product_id');

        $colors = ['blue', 'green', 'amber', 'purple', 'teal', 'indigo', 'pink', 'red'];

        $this->catalogCache = Product::query()
            ->with(['translations', 'category.translations', 'category.parent.translations', 'tags'])
            ->orderBy('id')
            ->get()
            ->map(function (Product $product) use ($stockByProduct, $colors) {
                $name = localized_name($product, 'name', "منتج #{$product->id}");
                $stock = $stockByProduct->get($product->id);
                $availableQty = (int) ($stock['available_qty'] ?? 0);
                $ppu = (float) ($stock['points_per_unit'] ?? $product->points_per_unit ?? 0);
                $image = $stock['image'] ?? $product->main_image;
                $imageUrl = $product->display_image_url
                    ?? ($image ? asset('storage/'.ltrim((string) $image, '/')) : null);
                $initials = collect(explode(' ', $name))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
                $categoryName = $stock['category_name']
                    ?? ($product->category
                        ? localized_name($product->category, 'name', $product->category->slug ?? 'تصنيف')
                        : 'بدون تصنيف');

                return [
                    'product_id' => $product->id,
                    'name' => $name,
                    'category_id' => $product->product_category_id,
                    'category_name' => $categoryName,
                    'is_accessories' => (bool) ($stock['is_accessories'] ?? $product->category?->isAccessoriesFamily()),
                    'tags' => $stock['tags'] ?? $product->tags->pluck('name')->values()->all(),
                    'tag' => $stock['tag'] ?? ($product->tags->first()?->name ?: 'أخرى'),
                    'group_key' => $stock['group_key'] ?? 'other',
                    'group_name' => $stock['group_name'] ?? ($product->tags->first()?->name ?: 'أخرى'),
                    'product_type' => $stock['product_type'] ?? null,
                    'points_per_unit' => $ppu,
                    'available_qty' => $availableQty,
                    'can_distribute' => $availableQty > 0 && $ppu > 0,
                    'image' => $imageUrl,
                    'initials' => $initials ?: 'MG',
                    'color_class' => $colors[crc32($name) % count($colors)],
                ];
            })
            ->sortBy([
                fn ($r) => (bool) ($r['is_accessories'] ?? false) ? 0 : 1,
                fn ($r) => mb_strtolower((string) ($r['category_name'] ?? '')),
                fn ($r) => mb_strtolower((string) ($r['group_name'] ?? 'ىىى')),
                fn ($r) => mb_strtolower((string) $r['name']),
            ])
            ->values();

        return $this->catalogCache;
    }

    /** Alias for stock-limit helper compatibility */
    protected function stockRows(): Collection
    {
        return $this->catalogRows();
    }

    public function getFilteredStockProperty(): Collection
    {
        return $this->catalogRows()
            ->when($this->stockFilter === 'available', fn ($c) => $c->filter(
                fn ($r) => (bool) ($r['can_distribute'] ?? false)
            ))
            ->when($this->stockFilter === 'order', fn ($c) => $c->filter(
                fn ($r) => ! (bool) ($r['can_distribute'] ?? false)
            ))
            ->when($this->selectedCategoryId, function (Collection $c) {
                $catId = (int) $this->selectedCategoryId;
                $allowed = ProductCategory::query()
                    ->whereKey($catId)
                    ->orWhere('parent_id', $catId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                return $c->filter(fn ($r) => in_array((int) ($r['category_id'] ?? 0), $allowed, true));
            })
            ->when($this->search, function ($c) {
                $term = mb_strtolower(trim($this->search));

                return $c->filter(function ($r) use ($term) {
                    if (str_contains(mb_strtolower($r['name']), $term)) {
                        return true;
                    }
                    foreach ($r['tags'] ?? [] as $tag) {
                        if (str_contains(mb_strtolower((string) $tag), $term)) {
                            return true;
                        }
                    }

                    return str_contains(mb_strtolower((string) ($r['group_name'] ?? '')), $term);
                });
            })
            ->values();
    }

    /**
     * Nested browse tree for POS:
     * - All categories: Category → Tag → products
     * - One category selected: Tag → products only
     *
     * @return Collection<int, array{category_name:?string, tags: Collection<string, Collection<int, array>>}>
     */
    public function getGroupedStockProperty(): Collection
    {
        $rows = $this->filteredStock;

        // Single category filter → only tag sections.
        if ($this->selectedCategoryId) {
            return collect([
                [
                    'category_name' => null,
                    'tags' => $rows
                        ->groupBy(fn ($r) => (string) ($r['group_name'] ?? $r['tag'] ?? 'أخرى'))
                        ->sortKeys(),
                ],
            ]);
        }

        // Accessories-first: category then tags under each.
        return $rows
            ->groupBy(fn ($r) => (string) ($r['category_name'] ?? 'بدون تصنيف'))
            ->sortKeys()
            ->map(fn (Collection $catRows, string $catName) => [
                'category_name' => $catName,
                'is_accessories' => (bool) ($catRows->first()['is_accessories'] ?? false),
                'tags' => $catRows
                    ->groupBy(fn ($r) => (string) ($r['group_name'] ?? $r['tag'] ?? 'أخرى'))
                    ->sortKeys(),
            ])
            ->sortByDesc(fn ($block) => (int) ($block['is_accessories'] ?? false))
            ->values();
    }

    public function getCartPointsProperty(): int
    {
        return (int) collect($this->cart)->sum(fn ($l) => floor($l['quantity'] * $l['points_per_unit']));
    }

    public function getQtyModalProductProperty(): ?array
    {
        if (! $this->qtyModalProductId) {
            return null;
        }

        return $this->catalogRows()->firstWhere('product_id', $this->qtyModalProductId);
    }

    public function selectCategory(?int $id): void
    {
        $this->selectedCategoryId = $id;
    }

    public function handleProductClick(int $productId): void
    {
        $row = $this->catalogRows()->firstWhere('product_id', $productId);

        if (! $row) {
            return;
        }

        if ($this->isWholesaleOrderMode()) {
            $this->qtyModalProductId = $productId;
            $this->qtyModalAmount = 1;

            return;
        }

        if (! ($row['can_distribute'] ?? false)) {
            $this->setStockFilter('order');
            $this->qtyModalProductId = $productId;
            $this->qtyModalAmount = 1;
            Notification::make()
                ->info()
                ->title('وضع طلب من الجملة')
                ->body('اختر موزّع الجملة من الأعلى ثم أكّد الكمية لإضافتها لطلب التوريد.')
                ->send();

            return;
        }

        $this->qtyModalProductId = $productId;
        $this->qtyModalAmount = 1;
    }

    public function closeQtyModal(): void
    {
        $this->qtyModalProductId = null;
        $this->qtyModalAmount = 1;
    }

    public function confirmQtyModal(): void
    {
        $productId = $this->qtyModalProductId;
        if (! $productId) {
            return;
        }

        $row = $this->catalogRows()->firstWhere('product_id', $productId);
        if (! $row) {
            $this->closeQtyModal();

            return;
        }

        $wholesaleMode = $this->isWholesaleOrderMode();
        if (! $wholesaleMode && ! ($row['can_distribute'] ?? false)) {
            $this->closeQtyModal();

            return;
        }

        $maxQty = $wholesaleMode ? 99999 : (int) $row['available_qty'];
        $qty = max(1, (int) $this->qtyModalAmount);

        if (! $wholesaleMode && $qty > $maxQty) {
            $this->notifyStockLimit($row['name'], $maxQty);
            $qty = $maxQty;
        }

        $key = (string) $productId;
        if (isset($this->cart[$key])) {
            $newQty = $wholesaleMode
                ? (int) $this->cart[$key]['quantity'] + $qty
                : min($maxQty, (int) $this->cart[$key]['quantity'] + $qty);
            $this->cart[$key]['quantity'] = $newQty;
            $this->cart[$key]['available_qty'] = $maxQty;
        } else {
            $this->cart[$key] = [
                'product_id' => $productId,
                'name' => $row['name'],
                'quantity' => $qty,
                'points_per_unit' => (float) $row['points_per_unit'],
                'image' => $row['image'],
                'initials' => $row['initials'],
                'color_class' => $row['color_class'],
                'available_qty' => $maxQty,
            ];
        }

        $this->closeQtyModal();
    }

    public function addToCart(int $productId): void
    {
        $this->handleProductClick($productId);
    }

    public function increment(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        if ($this->isWholesaleOrderMode()) {
            $this->setQuantity($key, (int) $this->cart[$key]['quantity'] + 1);

            return;
        }

        $maxQty = $this->resolveAvailableQty(
            (int) $this->cart[$key]['product_id'],
            (int) $this->cart[$key]['available_qty'],
        );
        $this->cart[$key]['available_qty'] = $maxQty;
        $this->setQuantity($key, (int) $this->cart[$key]['quantity'] + 1);
    }

    public function decrement(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $this->setQuantity($key, (int) $this->cart[$key]['quantity'] - 1);
    }

    public function removeFromCart(string $key): void
    {
        unset($this->cart[$key]);
    }

    public function clearCart(): void
    {
        $this->cart = [];
    }

    public function checkout(): void
    {
        if (empty($this->cart)) {
            Notification::make()->warning()->title('السلة فارغة')->send();

            return;
        }

        if ($this->isWholesaleOrderMode()) {
            $this->checkoutWholesaleOrder();

            return;
        }

        if (! $this->plumberId) {
            Notification::make()->danger()->title('اختر السباك أولاً')->send();

            return;
        }

        $plumber = User::find($this->plumberId);
        $trader = auth()->user();

        if (! $plumber || $plumber->role !== 'plumber') {
            Notification::make()->danger()->title('سباك غير صحيح')->send();

            return;
        }

        try {
            $lines = collect($this->cart)->map(fn ($line) => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
            ])->values()->all();

            $totalPoints = $this->cartPoints;

            app(PlumberDistributionPosService::class)->issueToPlumber(
                $trader,
                $plumber,
                $lines,
                $trader,
            );

            Notification::make()
                ->success()
                ->title('تم التوزيع ✓')
                ->body("{$totalPoints} نقطة وصلت لـ {$plumber->name}")
                ->send();

            if ((string) config('services.whatsapp.driver', 'log') === 'log') {
                $chatUrl = app(\App\Services\CustomerWhatsAppNotifier::class)->plumberSaleChatUrl(
                    $plumber,
                    $trader,
                    (int) $totalPoints,
                    max(1, count($lines)),
                );
                if ($chatUrl) {
                    $this->js('window.open('.\Illuminate\Support\Js::from($chatUrl).', "_blank", "noopener")');
                }
            }

            $this->cart = [];
            $this->plumberId = null;
            $this->plumberSearch = '';
            $this->catalogCache = null;

        } catch (\DomainException $e) {
            Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
        }
    }

    protected function checkoutWholesaleOrder(): void
    {
        if (! $this->supplierId) {
            Notification::make()->danger()->title('اختر موزّع الجملة أولاً')->send();

            return;
        }

        $lines = collect($this->cart)->map(fn ($line) => [
            'product_id' => (int) $line['product_id'],
            'quantity' => (int) $line['quantity'],
        ])->values()->all();

        try {
            $order = app(OrderService::class)->place(
                requester: auth()->user(),
                channel: OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL,
                lines: $lines,
                meta: [
                    'supplier_id' => $this->supplierId,
                    'wholesale_distributor_id' => $this->supplierId,
                ],
            );

            Notification::make()
                ->success()
                ->title('تم إرسال الطلب للجملة ✓')
                ->body("رقم الطلب {$order->order_number} — {$order->total_quantity} وحدة لـ ".($this->selectedWholesaler?->brand_name ?: $this->selectedWholesaler?->name ?: 'الموزّع'))
                ->send();

            $this->cart = [];
            $this->catalogCache = null;
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('تعذّر إنشاء الطلب')->body($e->getMessage())->send();
        }
    }
}

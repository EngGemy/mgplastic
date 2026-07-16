<?php

namespace App\Filament\Trader\Pages;

use App\Filament\Concerns\NotifiesPosStockLimit;
use App\Filament\Concerns\SetsCartQuantity;
use App\Filament\Trader\Resources\TraderOrderResource;
use App\Models\Invoice;
use App\Models\InvoiceDistributionItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\NetworkInventoryService;
use Filament\Notifications\Actions\Action as NotificationAction;
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

    public ?int $plumberId = null;

    public ?int $selectedCategoryId = null;

    public string $search = '';

    public string $plumberSearch = '';

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
            ->count();
    }

    public function getSelectedPlumberProperty(): ?User
    {
        if (! $this->plumberId) {
            return null;
        }

        return User::query()
            ->where('role', 'plumber')
            ->whereKey($this->plumberId)
            ->first(['id', 'name', 'phone', 'network_code']);
    }

    public function selectPlumber(int $id): void
    {
        $exists = User::query()
            ->where('role', 'plumber')
            ->where('is_active', true)
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

    public function getCategoriesProperty(): Collection
    {
        $productIds = $this->catalogRows()->pluck('product_id')->all();

        if ($productIds === []) {
            return collect();
        }

        return ProductCategory::query()
            ->with('translations')
            ->whereNull('parent_id')
            ->where(function ($q) use ($productIds) {
                $q->whereHas('products', fn ($p) => $p->whereIn('id', $productIds))
                    ->orWhereHas('children.products', fn ($p) => $p->whereIn('id', $productIds));
            })
            ->orderBy('id')
            ->get();
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
            ->with(['translations', 'category.translations'])
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

                return [
                    'product_id' => $product->id,
                    'name' => $name,
                    'category_id' => $product->product_category_id,
                    'points_per_unit' => $ppu,
                    'available_qty' => $availableQty,
                    'can_distribute' => $availableQty > 0 && $ppu > 0,
                    'image' => $imageUrl,
                    'initials' => $initials ?: 'MG',
                    'color_class' => $colors[crc32($name) % count($colors)],
                ];
            })
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

                return $c->filter(fn ($r) => str_contains(mb_strtolower($r['name']), $term));
            })
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

        if (! ($row['can_distribute'] ?? false)) {
            $this->offerOrderInstead($productId, (string) $row['name']);

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
        if (! $row || ! ($row['can_distribute'] ?? false)) {
            $this->closeQtyModal();

            return;
        }

        $maxQty = (int) $row['available_qty'];
        $qty = max(1, (int) $this->qtyModalAmount);

        if ($qty > $maxQty) {
            $this->notifyStockLimit($row['name'], $maxQty);
            $qty = $maxQty;
        }

        $key = (string) $productId;
        if (isset($this->cart[$key])) {
            $newQty = min($maxQty, (int) $this->cart[$key]['quantity'] + $qty);
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

    protected function offerOrderInstead(int $productId, string $name): void
    {
        $url = TraderOrderResource::getUrl('create').'?product='.$productId;

        Notification::make()
            ->warning()
            ->title('لا توجد نقاط متاحة لهذا المنتج')
            ->body("«{$name}» غير متوفر في مخزون النقاط لديك. يمكنك طلبه من موزّع الجملة.")
            ->persistent()
            ->actions([
                NotificationAction::make('order')
                    ->label('إضافة للطلبيات')
                    ->button()
                    ->url($url),
            ])
            ->send();
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
            $items = $this->buildDistributionItems();

            if (empty($items)) {
                Notification::make()->danger()->title('لا توجد فواتير متاحة للتوزيع')->send();

                return;
            }

            $grouped = collect($items)->groupBy('invoice_id');
            $totalPoints = $this->cartPoints;

            foreach ($grouped as $invoiceId => $groupItems) {
                $invoice = Invoice::findOrFail($invoiceId);
                $first = $groupItems->first();

                $distribution = app(DistributionService::class)->createDistribution(
                    invoice: $invoice,
                    fromUser: $trader,
                    toUser: $plumber,
                    tier: 3,
                    items: $groupItems->map(fn ($i) => [
                        'invoice_item_id' => $i['invoice_item_id'],
                        'quantity' => $i['quantity'],
                    ])->values()->all(),
                    parentId: $first['parent_distribution_id'] ?? null,
                );

                app(DistributionService::class)->confirmDistribution($distribution->fresh(['items']));
            }

            Notification::make()
                ->success()
                ->title('تم التوزيع ✓')
                ->body("{$totalPoints} نقطة وصلت لـ {$plumber->name}")
                ->send();

            $this->cart = [];
            $this->plumberId = null;
            $this->plumberSearch = '';
            $this->catalogCache = null;

        } catch (\DomainException $e) {
            Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
        }
    }

    /**
     * @return array<int, array{invoice_item_id:int, quantity:int, invoice_id:int, parent_distribution_id:int}>
     */
    private function buildDistributionItems(): array
    {
        $items = [];
        $trader = auth()->user();

        foreach ($this->cart as $line) {
            $distItem = InvoiceDistributionItem::query()
                ->join('invoice_distributions as d', 'd.id', '=', 'invoice_distribution_items.distribution_id')
                ->join('invoice_items as ii', 'ii.id', '=', 'invoice_distribution_items.invoice_item_id')
                ->where('d.to_user_id', $trader->id)
                ->where('d.tier', 2)
                ->whereIn('d.status', ['confirmed', 'points_awarded'])
                ->where('ii.product_id', $line['product_id'])
                ->select(
                    'invoice_distribution_items.invoice_item_id',
                    'ii.invoice_id',
                    'd.id as parent_dist_id'
                )
                ->first();

            if (! $distItem) {
                continue;
            }

            $items[] = [
                'invoice_item_id' => $distItem->invoice_item_id,
                'quantity' => $line['quantity'],
                'invoice_id' => $distItem->invoice_id,
                'parent_distribution_id' => $distItem->parent_dist_id,
            ];
        }

        return $items;
    }
}

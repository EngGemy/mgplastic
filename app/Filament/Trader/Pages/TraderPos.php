<?php

namespace App\Filament\Trader\Pages;

use App\Filament\Concerns\NotifiesPosStockLimit;
use App\Models\Invoice;
use App\Models\InvoiceDistributionItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\NetworkInventoryService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class TraderPos extends Page
{
    use NotifiesPosStockLimit;

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

    protected ?Collection $stockCache = null;

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
        $stockProductIds = $this->stockRows()->pluck('product_id')->toArray();

        if ($stockProductIds === []) {
            return collect();
        }

        return ProductCategory::with('translations')
            ->whereHas('products', fn ($q) => $q->whereIn('id', $stockProductIds))
            ->get();
    }

    protected function stockRows(): Collection
    {
        if ($this->stockCache !== null) {
            return $this->stockCache;
        }

        $stock = app(NetworkInventoryService::class)->stockForRetailTrader(auth()->user());

        $products = Product::whereIn('id', $stock->pluck('product_id')->toArray())
            ->with(['translations', 'category.translations', 'images'])
            ->get()
            ->keyBy('id');

        $this->stockCache = $stock->map(function ($row) use ($products) {
            $product = $products->get($row['product_id']);
            if (! $product) {
                return null;
            }

            $name = $product->translate('ar')?->name ?? $product->translate('en')?->name ?? 'منتج';
            $image = $row['image'] ?? $product->main_image;
            $imageUrl = $image ? asset('storage/'.ltrim($image, '/')) : null;
            $initials = collect(explode(' ', $name))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
            $colors = ['blue', 'green', 'amber', 'purple', 'teal', 'indigo', 'pink', 'red'];

            return [
                'product_id' => $row['product_id'],
                'name' => $name,
                'category_id' => $product->product_category_id,
                'points_per_unit' => $row['points_per_unit'],
                'available_qty' => $row['available_qty'],
                'image' => $imageUrl,
                'initials' => $initials,
                'color_class' => $colors[crc32($name) % count($colors)],
            ];
        })->filter()->values();

        return $this->stockCache;
    }

    public function getFilteredStockProperty(): Collection
    {
        return $this->stockRows()
            ->filter(fn ($r) => (float) ($r['points_per_unit'] ?? 0) > 0)
            ->when($this->selectedCategoryId, fn ($c) => $c->filter(
                fn ($r) => (int) $r['category_id'] === (int) $this->selectedCategoryId
            ))
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

    public function selectCategory(?int $id): void
    {
        $this->selectedCategoryId = $id;
    }

    public function addToCart(int $productId): void
    {
        $row = $this->stockRows()->firstWhere('product_id', $productId);
        if (! $row || $row['available_qty'] <= 0) {
            return;
        }

        $key = (string) $productId;
        $maxQty = (int) $row['available_qty'];

        if (isset($this->cart[$key])) {
            if ($this->cart[$key]['quantity'] >= $maxQty) {
                $this->notifyStockLimit($this->cart[$key]['name'], $maxQty);

                return;
            }
            $this->cart[$key]['quantity']++;
            $this->cart[$key]['available_qty'] = $maxQty;
        } else {
            $this->cart[$key] = [
                'product_id' => $productId,
                'name' => $row['name'],
                'quantity' => 1,
                'points_per_unit' => (float) $row['points_per_unit'],
                'image' => $row['image'],
                'initials' => $row['initials'],
                'color_class' => $row['color_class'],
                'available_qty' => $maxQty,
            ];
        }
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

        if ($this->cart[$key]['quantity'] >= $maxQty) {
            $this->notifyStockLimit($this->cart[$key]['name'], $maxQty);

            return;
        }

        $this->cart[$key]['quantity']++;
    }

    public function decrement(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }
        if ($this->cart[$key]['quantity'] <= 1) {
            unset($this->cart[$key]);

            return;
        }
        $this->cart[$key]['quantity']--;
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
            $this->stockCache = null;

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

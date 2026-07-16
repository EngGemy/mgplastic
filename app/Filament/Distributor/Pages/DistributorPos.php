<?php

namespace App\Filament\Distributor\Pages;

use App\Filament\Concerns\NotifiesPosStockLimit;
use App\Filament\Concerns\SetsCartQuantity;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\RetailDistributionPosService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class DistributorPos extends Page
{
    use NotifiesPosStockLimit;
    use SetsCartQuantity;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'كاشير البيع للقطاعي';

    protected static ?string $title = 'بيع للتاجر القطاعي';

    protected static ?string $navigationGroup = 'نظام النقاط';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.distributor.pages.distributor-pos';

    public ?int $retailTraderId = null;

    public ?int $selectedInvoiceId = null;

    public ?int $selectedCategoryId = null;

    public string $search = '';

    /** @var array<string, array<string, mixed>> */
    public array $cart = [];

    protected ?Collection $stockCache = null;

    public function mount(): void
    {
        $traders = $this->retailTraders;
        if ($traders->count() === 1) {
            $this->retailTraderId = (int) $traders->first()->id;
        }
    }

    public function updatedRetailTraderId(mixed $value): void
    {
        $this->retailTraderId = ($value === '' || $value === null) ? null : (int) $value;
    }

    public function getRetailTradersProperty(): Collection
    {
        return app(\App\Services\RetailNetworkLinkService::class)
            ->linkedRetailersFor(auth()->user());
    }

    public function getCategoriesProperty(): Collection
    {
        $stockProductIds = $this->stockRows()->pluck('product_id')->toArray();

        if ($stockProductIds === []) {
            return collect();
        }

        return ProductCategory::query()
            ->with('translations')
            ->whereHas('products', fn ($q) => $q->whereIn('id', $stockProductIds))
            ->get();
    }

    protected function stockRows(): Collection
    {
        if ($this->stockCache !== null) {
            return $this->stockCache;
        }

        $stock = app(NetworkInventoryService::class)->stockForWholesaler(auth()->user());

        $productIds = $stock->pluck('product_id')->toArray();
        $products = Product::whereIn('id', $productIds)
            ->with(['translations', 'category.translations', 'images'])
            ->get()
            ->keyBy('id');

        $this->stockCache = $stock->map(function ($row) use ($products) {
            $product = $products->get($row['product_id']);
            if (! $product) {
                return null;
            }

            $name = $product->translate('ar')?->name
                ?? $product->translate('en')?->name
                ?? 'منتج';
            $image = $row['image'] ?? $product->main_image;
            $imageUrl = $image ? asset('storage/'.ltrim($image, '/')) : null;
            $initials = collect(explode(' ', $name))->take(2)
                ->map(fn ($w) => mb_substr($w, 0, 1))->implode('');

            return [
                'product_id' => $row['product_id'],
                'name' => $name,
                'category_id' => $product->product_category_id,
                'category_name' => $product->category?->translate('ar')?->name ?? '',
                'points_per_unit' => $row['points_per_unit'],
                'available_qty' => $row['available_qty'],
                'image' => $imageUrl,
                'initials' => $initials,
                'color_class' => $this->colorFromName($name),
            ];
        })->filter()->values();

        return $this->stockCache;
    }

    private function colorFromName(string $name): string
    {
        $colors = ['blue', 'green', 'amber', 'purple', 'teal', 'indigo', 'pink', 'red'];

        return $colors[crc32($name) % count($colors)];
    }

    public function getFilteredStockProperty(): Collection
    {
        return $this->stockRows()
            ->filter(fn ($row) => (float) ($row['points_per_unit'] ?? 0) > 0)
            ->when($this->selectedCategoryId, fn ($c) => $c->filter(
                fn ($row) => (int) $row['category_id'] === (int) $this->selectedCategoryId
            ))
            ->when($this->search, function ($c) {
                $term = mb_strtolower(trim($this->search));

                return $c->filter(fn ($row) => str_contains(mb_strtolower($row['name']), $term));
            })
            ->values();
    }

    public function getCartPointsProperty(): int
    {
        return (int) collect($this->cart)->sum(
            fn ($line) => (int) floor($line['quantity'] * $line['points_per_unit'])
        );
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

        if (! $this->retailTraderId) {
            Notification::make()->danger()->title('اختر التاجر القطاعي أولاً')->send();

            return;
        }

        $trader = User::find($this->retailTraderId);
        $wholesaler = auth()->user();

        if (! $trader || ! app(\App\Services\RetailNetworkLinkService::class)->isLinked($wholesaler, $trader)) {
            Notification::make()->danger()->title('تاجر غير مسموح — أضفه عبر الرقم الموحّد أولاً')->send();

            return;
        }

        try {
            $lines = collect($this->cart)->map(fn ($line) => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
            ])->values()->all();

            $totalPoints = $this->cartPoints;

            $outgoing = app(RetailDistributionPosService::class)->issueToRetailTrader(
                $wholesaler,
                $trader,
                $lines,
                $wholesaler,
            );

            Notification::make()
                ->success()
                ->title('تم البيع بنجاح ✓')
                ->body(count($outgoing).' فاتورة — '.$totalPoints.' نقطة للتاجر '.$trader->name)
                ->send();

            $this->cart = [];
            $this->retailTraderId = null;
            $this->stockCache = null;

        } catch (\DomainException $e) {
            Notification::make()->danger()->title('خطأ')->body($e->getMessage())->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->danger()
                ->title('تعذّر إتمام البيع')
                ->body('حدث خطأ غير متوقع — أعد المحاولة أو تواصل مع الدعم')
                ->send();
        }
    }
}

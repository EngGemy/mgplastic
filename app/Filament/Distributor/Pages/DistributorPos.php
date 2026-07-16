<?php

namespace App\Filament\Distributor\Pages;

use App\Filament\Concerns\NotifiesPosStockLimit;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\NetworkInventoryService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class DistributorPos extends Page
{
    use NotifiesPosStockLimit;

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
            $items = $this->buildDistributionItems();

            if (empty($items)) {
                Notification::make()->danger()->title('لا توجد كميات متاحة للتوزيع')->send();

                return;
            }

            $grouped = collect($items)->groupBy('invoice_id');
            $distributionCount = 0;
            $totalPoints = $this->cartPoints;

            foreach ($grouped as $invoiceId => $groupItems) {
                $invoice = Invoice::findOrFail($invoiceId);
                $first = $groupItems->first();

                $distribution = app(DistributionService::class)->createDistribution(
                    invoice: $invoice,
                    fromUser: $wholesaler,
                    toUser: $trader,
                    tier: 2,
                    items: $groupItems->map(fn ($i) => [
                        'invoice_item_id' => $i['invoice_item_id'],
                        'quantity' => $i['quantity'],
                    ])->values()->all(),
                    parentId: $first['parent_distribution_id'] ?? null,
                );

                app(DistributionService::class)->confirmDistribution($distribution->fresh(['items']));
                $distributionCount++;
            }

            Notification::make()
                ->success()
                ->title('تم البيع بنجاح ✓')
                ->body("{$distributionCount} توزيع مؤكد — {$totalPoints} نقطة للتاجر {$trader->name}")
                ->send();

            $this->cart = [];
            $this->retailTraderId = null;
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
        $wholesaler = auth()->user();

        foreach ($this->cart as $line) {
            $productId = $line['product_id'];
            $qty = $line['quantity'];

            $invoiceItem = InvoiceItem::query()
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoices.wholesale_distributor_id', $wholesaler->id)
                ->where('invoices.invoice_type', 'wholesale_pos')
                ->where('invoices.invoice_flow', 'incoming')
                ->where('invoices.status', 'approved')
                ->where('invoice_items.product_id', $productId)
                ->select('invoice_items.*')
                ->first();

            if (! $invoiceItem) {
                continue;
            }

            $tier1 = InvoiceDistribution::where('invoice_id', $invoiceItem->invoice_id)
                ->where('tier', 1)
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->first();

            if (! $tier1) {
                continue;
            }

            $items[] = [
                'invoice_item_id' => $invoiceItem->id,
                'quantity' => $qty,
                'invoice_id' => $invoiceItem->invoice_id,
                'parent_distribution_id' => $tier1->id,
            ];
        }

        return $items;
    }
}

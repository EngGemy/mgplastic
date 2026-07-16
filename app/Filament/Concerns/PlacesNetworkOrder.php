<?php

namespace App\Filament\Concerns;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\OrderService;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

trait PlacesNetworkOrder
{
    use SetsCartQuantity;

    public string $search = '';

    public ?int $selectedCategoryId = null;

    public string $note = '';

    /** @var array<string, array{product_id:int, name:string, quantity:int, points_per_unit:float, image:?string, initials:string, color_class:string}> */
    public array $cart = [];

    protected ?Collection $catalogCache = null;

    abstract protected function orderChannel(): string;

    abstract protected function successRedirectUrl(): string;

    abstract protected function emptyCartMessage(): string;

    public function selectCategory(?int $id): void
    {
        $this->selectedCategoryId = $id;
    }

    public function getCategoriesProperty(): Collection
    {
        $productIds = $this->catalogRows()->pluck('product_id')->all();

        if ($productIds === []) {
            return collect();
        }

        // Top-level categories only (avoids duplicate child labels like «حسب النظام الأمريكي»).
        return ProductCategory::query()
            ->with('translations')
            ->whereNull('parent_id')
            ->where(function ($q) use ($productIds) {
                $q->whereHas('products', fn ($p) => $p->whereIn('id', $productIds))
                    ->orWhereHas('children.products', fn ($p) => $p->whereIn('id', $productIds));
            })
            ->orderBy('id')
            ->get()
            ->map(function (ProductCategory $c) {
                $c->display_name = localized_name($c, 'name', $c->slug ?? "فئة #{$c->id}");

                return $c;
            });
    }

    public function getFilteredCatalogProperty(): Collection
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

                return $c->filter(fn ($row) => in_array((int) ($row['category_id'] ?? 0), $allowed, true));
            })
            ->when($this->search, function (Collection $c) {
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

    public function getCartQuantityProperty(): int
    {
        return (int) collect($this->cart)->sum('quantity');
    }

    protected function catalogRows(): Collection
    {
        if ($this->catalogCache !== null) {
            return $this->catalogCache;
        }

        $this->catalogCache = Product::query()
            ->with(['translations', 'category.translations'])
            ->orderBy('id')
            ->get()
            ->map(function (Product $p) {
                $name = localized_name($p, 'name', "منتج #{$p->id}");
                $initials = collect(explode(' ', $name))->take(2)
                    ->map(fn ($w) => mb_substr($w, 0, 1))->implode('');

                return [
                    'product_id' => $p->id,
                    'name' => $name,
                    'category_id' => $p->product_category_id,
                    'points_per_unit' => (float) ($p->points_per_unit ?? 0),
                    'image' => $p->display_image_url,
                    'initials' => $initials ?: 'MG',
                    'color_class' => $this->colorFromName($name),
                ];
            })
            ->values();

        return $this->catalogCache;
    }

    private function colorFromName(string $name): string
    {
        $colors = ['blue', 'green', 'amber', 'purple', 'teal', 'indigo', 'pink', 'red'];

        return $colors[crc32($name) % count($colors)];
    }

    public function addToCart(int $productId): void
    {
        $row = $this->catalogRows()->firstWhere('product_id', $productId);

        if (! $row) {
            return;
        }

        $key = (string) $productId;

        if (isset($this->cart[$key])) {
            $this->cart[$key]['quantity']++;
        } else {
            $this->cart[$key] = [
                'product_id' => $productId,
                'name' => $row['name'],
                'quantity' => 1,
                'points_per_unit' => (float) $row['points_per_unit'],
                'image' => $row['image'],
                'initials' => $row['initials'],
                'color_class' => $row['color_class'],
            ];
        }
    }

    public function increment(string $key): void
    {
        if (isset($this->cart[$key])) {
            $this->setQuantity($key, (int) $this->cart[$key]['quantity'] + 1);
        }
    }

    public function decrement(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $this->setQuantity($key, (int) $this->cart[$key]['quantity'] - 1);
    }

    public function prefillFromRequest(): void
    {
        $productId = (int) request()->query('product', 0);
        $qty = max(1, (int) request()->query('qty', 1));

        if ($productId <= 0) {
            return;
        }

        $this->addToCart($productId);
        $this->setQuantity((string) $productId, $qty);
    }

    public function removeFromCart(string $key): void
    {
        unset($this->cart[$key]);
    }

    public function clearCart(): void
    {
        $this->cart = [];
    }

    public function submitOrder(): void
    {
        if (empty($this->cart)) {
            Notification::make()->warning()->title($this->emptyCartMessage())->send();

            return;
        }

        $lines = array_values(collect($this->cart)->map(fn ($line) => [
            'product_id' => (int) $line['product_id'],
            'quantity' => (int) $line['quantity'],
        ])->all());

        try {
            $order = app(OrderService::class)->place(
                requester: auth()->user(),
                channel: $this->orderChannel(),
                lines: $lines,
                meta: ['note' => $this->note ?: null],
            );

            Notification::make()
                ->success()
                ->title('تم إرسال الطلب ✓')
                ->body("رقم الطلب {$order->order_number} — {$order->total_quantity} وحدة")
                ->send();

            $this->redirect($this->successRedirectUrl());
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('تعذّر إنشاء الطلب')->body($e->getMessage())->send();
        }
    }
}

<?php

namespace App\Filament\Concerns;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

trait PlacesNetworkOrder
{
    use SetsCartQuantity {
        setQuantity as private setPieceQuantity;
    }

    public string $search = '';

    public ?int $selectedCategoryId = null;

    public string $note = '';

    /** Wholesale distributor chosen by the retail trader before submit. */
    public ?int $supplierId = null;

    /**
     * @var array<string, array{
     *   product_id:int,
     *   name:string,
     *   quantity:int,
     *   boxes:?int,
     *   pieces_per_box:?int,
     *   points_per_unit:float,
     *   image:?string,
     *   initials:string,
     *   color_class:string
     * }>
     */
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

    public function needsWholesaleSupplier(): bool
    {
        return $this->orderChannel() === OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL;
    }

    /**
     * Approved wholesale shops the retail trader can order from.
     *
     * @return Collection<int, array{id:int, label:string}>
     */
    public function getWholesaleSuppliersProperty(): Collection
    {
        if (! $this->needsWholesaleSupplier()) {
            return collect();
        }

        $linkedIds = app(\App\Services\RetailNetworkLinkService::class)
            ->linkedWholesalerIds(auth()->user());

        return User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->orderBy('brand_name')
            ->orderBy('name')
            ->get(['id', 'name', 'brand_name', 'network_code'])
            ->sortBy(fn (User $user) => in_array((int) $user->id, $linkedIds, true) ? 0 : 1)
            ->map(function (User $user) use ($linkedIds) {
                $title = trim((string) ($user->brand_name ?: $user->name));
                $code = $user->network_code ? " — {$user->network_code}" : '';
                $badge = in_array((int) $user->id, $linkedIds, true) ? ' ★' : '';

                return [
                    'id' => (int) $user->id,
                    'label' => $title.$code.$badge,
                    'linked' => in_array((int) $user->id, $linkedIds, true),
                ];
            })
            ->values();
    }

    protected function prefillWholesaleSupplier(): void
    {
        if (! $this->needsWholesaleSupplier() || $this->supplierId) {
            return;
        }

        $user = auth()->user();
        if (! $user) {
            return;
        }

        $preferred = (int) ($user->parent_distributor_id ?? 0);
        $options = $this->wholesaleSuppliers->pluck('id')->all();

        if ($preferred > 0 && in_array($preferred, $options, true)) {
            $this->supplierId = $preferred;

            return;
        }

        if (count($options) === 1) {
            $this->supplierId = (int) $options[0];
        }
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

                $piecesPerBox = (int) ($p->pieces_per_box ?? 0);

                return [
                    'product_id' => $p->id,
                    'name' => $name,
                    'category_id' => $p->product_category_id,
                    'points_per_unit' => (float) ($p->points_per_unit ?? 0),
                    'pieces_per_box' => $piecesPerBox > 0 ? $piecesPerBox : null,
                    'order_unit' => $piecesPerBox > 0 ? 'box' : 'piece',
                    'image' => $p->display_image_url,
                    'initials' => $initials ?: 'MG',
                    'color_class' => $this->colorFromName($name),
                ];
            })
            ->values();

        return $this->catalogCache;
    }

    /** Factory orders use boxes when the product has pieces_per_box. */
    protected function usesFactoryBoxOrdering(): bool
    {
        return $this->orderChannel() === OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE;
    }

    public function isBoxed(string $key): bool
    {
        return $this->usesFactoryBoxOrdering()
            && (int) ($this->cart[$key]['pieces_per_box'] ?? 0) > 0;
    }

    public function setBoxes(string $key, mixed $value): void
    {
        if (! isset($this->cart[$key]) || ! $this->isBoxed($key)) {
            return;
        }

        $boxes = (int) $value;
        if ($boxes < 1) {
            unset($this->cart[$key]);

            return;
        }

        $pieces = (int) $this->cart[$key]['pieces_per_box'];
        $this->cart[$key]['boxes'] = $boxes;
        $this->cart[$key]['quantity'] = $boxes * $pieces;
    }

    public function setQuantity(string $key, mixed $value): void
    {
        if ($this->isBoxed($key)) {
            $this->setBoxes($key, $value);

            return;
        }

        $this->setPieceQuantity($key, $value);
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
        $piecesPerBox = $this->usesFactoryBoxOrdering()
            ? (int) ($row['pieces_per_box'] ?? 0)
            : 0;
        $boxed = $piecesPerBox > 0;

        if (isset($this->cart[$key])) {
            if ($this->isBoxed($key)) {
                $this->setBoxes($key, (int) $this->cart[$key]['boxes'] + 1);
            } else {
                $this->setQuantity($key, (int) $this->cart[$key]['quantity'] + 1);
            }

            return;
        }

        $this->cart[$key] = [
            'product_id' => $productId,
            'name' => $row['name'],
            'quantity' => $boxed ? $piecesPerBox : 1,
            'boxes' => $boxed ? 1 : null,
            'pieces_per_box' => $boxed ? $piecesPerBox : null,
            'points_per_unit' => (float) $row['points_per_unit'],
            'image' => $row['image'],
            'initials' => $row['initials'],
            'color_class' => $row['color_class'],
        ];
    }

    public function increment(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        if ($this->isBoxed($key)) {
            $this->setBoxes($key, (int) $this->cart[$key]['boxes'] + 1);

            return;
        }

        $this->setQuantity($key, (int) $this->cart[$key]['quantity'] + 1);
    }

    public function decrement(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        if ($this->isBoxed($key)) {
            $this->setBoxes($key, (int) $this->cart[$key]['boxes'] - 1);

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
        $key = (string) $productId;
        if ($this->isBoxed($key)) {
            $this->setBoxes($key, $qty);
        } else {
            $this->setQuantity($key, $qty);
        }
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

        if ($this->needsWholesaleSupplier() && ! $this->supplierId) {
            Notification::make()
                ->danger()
                ->title('اختر موزّع الجملة')
                ->body('حدد محل الجملة الذي تريد إرسال الطلب إليه قبل الإرسال.')
                ->send();

            return;
        }

        $lines = array_values(collect($this->cart)->map(function ($line) {
            $payload = [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
            ];

            if ((int) ($line['pieces_per_box'] ?? 0) > 0 && isset($line['boxes'])) {
                $payload['boxes'] = (int) $line['boxes'];
            }

            return $payload;
        })->all());

        try {
            $order = app(OrderService::class)->place(
                requester: auth()->user(),
                channel: $this->orderChannel(),
                lines: $lines,
                meta: [
                    'note' => $this->note ?: null,
                    'supplier_id' => $this->supplierId,
                    'wholesale_distributor_id' => $this->supplierId,
                ],
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

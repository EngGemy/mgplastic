<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Collection;

trait HandlesNetworkPosCart
{
    use SetsCartQuantity;
    use NotifiesPosStockLimit;

    /** @var array<string, array{product_id:int, name:string, quantity:int, points_per_unit:float, image:?string, max_qty:int}> */
    public array $cart = [];

    public string $search = '';

    public ?int $selectedCategoryId = null;

    abstract protected function stockRows(): Collection;

    public function selectCategory(?int $categoryId): void
    {
        $this->selectedCategoryId = $categoryId;
    }

    public function getFilteredStockProperty(): Collection
    {
        return $this->stockRows()
            ->filter(fn ($row) => (float) ($row['points_per_unit'] ?? 0) > 0)
            ->when($this->selectedCategoryId, fn (Collection $c) => $c->filter(
                fn ($row) => (int) ($row['category_id'] ?? 0) === (int) $this->selectedCategoryId
                    || $this->categoryMatchesChild($row, $this->selectedCategoryId)
            ))
            ->when($this->search, function (Collection $c) {
                $term = mb_strtolower(trim($this->search));

                return $c->filter(fn ($row) => str_contains(mb_strtolower($row['name']), $term));
            })
            ->values();
    }

    protected function categoryMatchesChild(array $row, int $categoryId): bool
    {
        return false;
    }

    public function getCartPointsProperty(): int
    {
        return (int) collect($this->cart)->sum(
            fn ($line) => (int) floor($line['quantity'] * $line['points_per_unit'])
        );
    }

    public function maxQtyForProduct(int $productId): int
    {
        $row = $this->stockRows()->firstWhere('product_id', $productId);

        return (int) ($row['available_qty'] ?? 0);
    }

    public function addProduct(int $productId): void
    {
        $row = $this->stockRows()->firstWhere('product_id', $productId);

        if (! $row || (int) $row['available_qty'] <= 0) {
            return;
        }

        $key = (string) $productId;

        if (isset($this->cart[$key])) {
            if ($this->cart[$key]['quantity'] >= (int) $row['available_qty']) {
                $this->notifyPosStockLimit($this->cart[$key]['name'], (int) $row['available_qty']);

                return;
            }
            $this->cart[$key]['quantity']++;
        } else {
            $this->cart[$key] = [
                'product_id' => $productId,
                'name' => $row['name'],
                'quantity' => 1,
                'points_per_unit' => (float) $row['points_per_unit'],
                'image' => $row['image'] ?? null,
                'max_qty' => (int) $row['available_qty'],
            ];
        }
    }

    public function incrementQty(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $this->setQuantity($key, (int) $this->cart[$key]['quantity'] + 1);
    }

    public function decrementQty(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $this->setQuantity($key, (int) $this->cart[$key]['quantity'] - 1);
    }

    public function removeLine(string $key): void
    {
        unset($this->cart[$key]);
    }

    /** @return array<int, array{product_id:int, quantity:int}> */
    protected function cartLines(): array
    {
        return array_values(collect($this->cart)->map(fn ($line) => [
            'product_id' => (int) $line['product_id'],
            'quantity' => (int) $line['quantity'],
        ])->all());
    }

    protected function notifyPosStockLimit(string $productName, int $maxQty): void
    {
        $this->notifyStockLimit($productName, $maxQty);
    }
}

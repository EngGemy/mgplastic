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
        // Show MG products with points AND catalog items without points (no-points sales).
        return $this->stockRows()
            ->when($this->selectedCategoryId, fn (Collection $c) => $c->filter(
                fn ($row) => (int) ($row['category_id'] ?? 0) === (int) $this->selectedCategoryId
                    || $this->categoryMatchesChild($row, $this->selectedCategoryId)
            ))
            ->when($this->search, function (Collection $c) {
                $term = mb_strtolower(trim($this->search));

                return $c->filter(function ($row) use ($term) {
                    if (str_contains(mb_strtolower($row['name']), $term)) {
                        return true;
                    }
                    foreach ($row['tags'] ?? [] as $tag) {
                        if (str_contains(mb_strtolower((string) $tag), $term)) {
                            return true;
                        }
                    }

                    return str_contains(mb_strtolower((string) ($row['group_name'] ?? $row['tag'] ?? '')), $term);
                });
            })
            ->sortBy([
                fn ($row) => (bool) ($row['is_accessories'] ?? false) ? 0 : 1,
                fn ($row) => mb_strtolower((string) ($row['category_name'] ?? '')),
                fn ($row) => mb_strtolower((string) ($row['group_name'] ?? $row['tag'] ?? 'ىىى')),
                fn ($row) => mb_strtolower((string) ($row['name'] ?? '')),
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{category_name:?string, tags: Collection}>
     */
    public function getGroupedStockProperty(): Collection
    {
        $rows = $this->filteredStock;

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
            $ppu = max(0, (float) ($row['points_per_unit'] ?? 0));
            $this->cart[$key] = [
                'product_id' => $productId,
                'name' => $row['name'],
                'quantity' => 1,
                'points_per_unit' => $ppu,
                'has_points' => $ppu > 0,
                'product_kind' => $ppu > 0 ? 'mg_points' : 'mg_no_points',
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

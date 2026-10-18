<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class LowStockAlertService
{
    public const THRESHOLD = 5;

    /**
     * Products with available qty at or below threshold (near zero).
     *
     * @return Collection<int, array{product_id:int, name:string, available_qty:int, image:?string}>
     */
    public function forUser(?User $user, int $threshold = self::THRESHOLD): Collection
    {
        if (! $user) {
            return collect();
        }

        $inventory = app(NetworkInventoryService::class);

        $stock = match (true) {
            $user->isWholesaleDistributor() || $user->isCentralWarehouse() => $inventory->stockForWholesaler($user),
            $user->isRetailTrader() => $inventory->stockForRetailTrader($user),
            default => collect(),
        };

        return $stock
            ->filter(fn (array $row) => (int) ($row['available_qty'] ?? 0) <= $threshold)
            ->map(fn (array $row) => [
                'product_id' => (int) $row['product_id'],
                'name' => (string) ($row['name'] ?? 'منتج'),
                'available_qty' => (int) ($row['available_qty'] ?? 0),
                'image' => $row['image'] ?? null,
            ])
            ->sortBy('available_qty')
            ->values();
    }
}

<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\MapsNetworkUser;
use App\Models\User;
use App\Services\NetworkInventoryService;

class GetStoreTool extends AbstractTool
{
    use MapsNetworkUser;

    public function name(): string
    {
        return 'get_store';
    }

    public function description(): string
    {
        return 'Get one store (wholesale or retail) by id/phone/network_code with wallet, linked partners count, and stock summary lines.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'user_id' => ['type' => 'integer'],
            'phone' => ['type' => 'string'],
            'network_code' => ['type' => 'string'],
            'include_stock' => ['type' => 'boolean', 'description' => 'Include inventory lines (default true)'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $query = User::query()->whereIn('role', ['wholesale_distributor', 'retail_trader']);

        if (! empty($arguments['user_id'])) {
            $query->whereKey((int) $arguments['user_id']);
        } elseif (! empty($arguments['phone'])) {
            $query->where('phone', $arguments['phone']);
        } elseif (! empty($arguments['network_code'])) {
            $query->where('network_code', $arguments['network_code']);
        } else {
            return ['found' => false, 'error' => 'Provide user_id, phone, or network_code'];
        }

        $store = $query->with(['city', 'country', 'parentDistributor:id,name,role,network_code'])->first();

        if (! $store) {
            return ['found' => false];
        }

        $payload = $this->mapUserBrief($store, true);
        $payload['address'] = $store->address;
        $payload['store_description'] = $store->store_description;
        $payload['city'] = $store->city?->name_ar ?? $store->city?->name_en;
        $payload['country'] = $store->country?->name_ar ?? $store->country?->name_en;
        $payload['parent_distributor'] = $store->parentDistributor
            ? [
                'id' => $store->parentDistributor->id,
                'name' => $store->parentDistributor->name,
                'network_code' => $store->parentDistributor->network_code,
            ]
            : null;

        if ($store->isWholesaleDistributor()) {
            $payload['linked_retailers_count'] = $store->linkedRetailTraders()->count();
            $payload['retail_traders_count'] = $store->retailTraders()->count();
        }

        if ($store->isRetailTrader()) {
            $payload['linked_wholesalers_count'] = $store->linkedWholesalers()->count();
            $payload['plumbers_count'] = $store->plumbers()->count();
        }

        $includeStock = array_key_exists('include_stock', $arguments)
            ? (bool) $arguments['include_stock']
            : true;

        if ($includeStock) {
            try {
                $inventory = app(NetworkInventoryService::class);
                $stock = $store->isWholesaleDistributor()
                    ? $inventory->stockForWholesaler($store)
                    : $inventory->stockForRetailTrader($store);

                $lines = collect($stock)->take(40)->map(fn (array $row) => [
                    'product_id' => $row['product_id'] ?? null,
                    'name' => $row['name'] ?? null,
                    'available_qty' => $row['available_qty'] ?? null,
                    'available_points' => $row['available_points'] ?? null,
                    'points_per_unit' => $row['points_per_unit'] ?? null,
                    'category_id' => $row['category_id'] ?? null,
                ])->values();
                $payload['stock_lines_returned'] = $lines->count();
                $payload['stock'] = $lines->all();
            } catch (\Throwable $e) {
                $payload['stock_error'] = $e->getMessage();
            }
        }

        return ['found' => true, 'store' => $payload];
    }
}

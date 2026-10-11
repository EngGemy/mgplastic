<?php

namespace App\Ai\Tools;

use App\Models\Order;
use Illuminate\Support\Facades\Schema;

class ListOrdersTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_orders';
    }

    public function description(): string
    {
        return 'List recent network orders (factory→wholesale, wholesale→retail, retail→plumber) with status and parties. Newest first.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'status' => ['type' => 'string'],
            'channel' => ['type' => 'string'],
            'requester_id' => ['type' => 'integer'],
            'supplier_id' => ['type' => 'integer'],
            'days' => ['type' => 'integer', 'description' => 'Default 60'],
            'limit' => ['type' => 'integer', 'description' => 'Default 30, max 100'],
        ]);
    }

    public function handle(array $arguments): array
    {
        if (! Schema::hasTable('orders')) {
            return ['available' => false, 'orders' => []];
        }

        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 30)));
        $days = max(1, min(365, (int) ($arguments['days'] ?? 60)));

        $query = Order::query()
            ->with([
                'requester:id,name,brand_name,role,network_code',
                'supplier:id,name,brand_name,role,network_code',
                'items',
            ])
            ->where('created_at', '>=', now()->subDays($days))
            ->latest('id');

        foreach (['status', 'channel', 'requester_id', 'supplier_id'] as $field) {
            if (isset($arguments[$field]) && $arguments[$field] !== '' && $arguments[$field] !== null) {
                $query->where($field, $arguments[$field]);
            }
        }

        $items = $query->limit($limit)->get();

        return [
            'available' => true,
            'window_days' => $days,
            'returned' => $items->count(),
            'latest_order' => $items->first() ? $this->mapOrder($items->first()) : null,
            'orders' => $items->map(fn (Order $o) => $this->mapOrder($o))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function mapOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'channel' => $order->channel,
            'requester' => $order->requester ? [
                'id' => $order->requester->id,
                'name' => $order->requester->brand_name ?: $order->requester->name,
                'role' => $order->requester->role,
            ] : null,
            'supplier' => $order->supplier ? [
                'id' => $order->supplier->id,
                'name' => $order->supplier->brand_name ?: $order->supplier->name,
                'role' => $order->supplier->role,
            ] : null,
            'items_count' => $order->items?->count() ?? 0,
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'updated_at' => optional($order->updated_at)?->toIso8601String(),
        ];
    }
}

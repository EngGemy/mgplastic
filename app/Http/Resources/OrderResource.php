<?php

namespace App\Http\Resources;

use App\Support\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'channel' => $this->channel,
            'channel_label' => OrderStatus::channelLabel($this->channel),
            'channel_color' => OrderStatus::channelColor($this->channel),

            'status' => $this->status,
            'status_label' => OrderStatus::label($this->status),
            'status_description' => OrderStatus::description($this->status),
            'status_color' => OrderStatus::color($this->status),
            'is_open' => OrderStatus::isOpen($this->status),
            'timeline' => $this->buildTimeline(),
            'available_actions' => $this->availableActions($request),

            'requester' => $this->when($this->relationLoaded('requester') && $this->requester, fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
                'brand_name' => $this->requester->brand_name,
            ]),
            'supplier' => $this->supplier_id
                ? $this->when($this->relationLoaded('supplier') && $this->supplier, fn () => [
                    'id' => $this->supplier->id,
                    'name' => $this->supplier->name,
                    'brand_name' => $this->supplier->brand_name,
                ])
                : null,

            'total_quantity' => (int) $this->total_quantity,
            'total_points' => (int) $this->total_points,

            'carrier_name' => $this->carrier_name,
            'tracking_number' => $this->tracking_number,
            'expected_delivery_at' => $this->expected_delivery_at?->toDateString(),

            'note' => $this->note,
            'supplier_note' => $this->supplier_note,

            'placed_at' => $this->placed_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => (int) $item->product_id,
                'name' => $item->name_snapshot,
                'quantity' => (int) $item->quantity,
                // Per-unit rate is admin-only; apps show line/order totals.
                'points_per_unit' => null,
                'line_points' => (int) $item->line_points,
            ])->values()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildTimeline(): array
    {
        $steps = OrderStatus::timeline();
        $currentIndex = array_search($this->status, $steps, true);
        $reachedUpTo = $currentIndex === false ? -1 : $currentIndex;

        return collect($steps)->map(fn ($step, $index) => [
            'status' => $step,
            'label' => OrderStatus::label($step),
            'icon' => OrderStatus::icon($step),
            'reached' => $index <= $reachedUpTo && ! OrderStatus::isFinal($this->status) || $step === $this->status,
            'current' => $step === $this->status,
        ])->all();
    }

    /**
     * Actions the authenticated user can call next (for mobile CTA buttons).
     *
     * @return list<string>
     */
    protected function availableActions(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        $isBuyer = (int) $this->requester_id === (int) $user->id;
        $isSupplier = $this->supplier_id !== null && (int) $this->supplier_id === (int) $user->id;
        $status = $this->status;
        $actions = [];

        if ($isSupplier && $this->isPlumberChannel()) {
            if (in_array($status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true)) {
                $actions[] = 'update_items';
                $actions[] = 'apply_stock';
            }
            if (in_array($status, [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPING], true)) {
                $actions[] = 'fulfill';
            }
            if ($status === OrderStatus::PLACED) {
                $actions[] = 'confirm';
            }
            if (in_array($status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true)) {
                $actions[] = 'ship';
                $actions[] = 'reject';
            }
        }

        if ($isSupplier && ! $this->isPlumberChannel()) {
            if ($status === OrderStatus::PLACED) {
                $actions[] = 'confirm';
            }
            if (in_array($status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true)) {
                $actions[] = 'ship';
                $actions[] = 'reject';
            }
            if (in_array($status, [OrderStatus::CONFIRMED, OrderStatus::SHIPPING], true)) {
                $actions[] = 'deliver';
            }
        }

        if ($isBuyer) {
            if (in_array($status, [OrderStatus::CONFIRMED, OrderStatus::SHIPPING], true)) {
                $actions[] = 'receive';
            }
            if (in_array($status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true)) {
                $actions[] = 'cancel';
            }
        }

        return array_values(array_unique($actions));
    }
}

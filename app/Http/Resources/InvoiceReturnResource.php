<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceReturnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'invoice_id' => (int) $this->invoice_id,
            'distribution_id' => (int) $this->distribution_id,
            'tier' => (int) $this->tier,
            'tier_label' => match ((int) $this->tier) {
                2 => 'جملة → قطاعي',
                3 => 'قطاعي → سباك',
                default => 'توزيع #'.$this->tier,
            },
            'status' => $this->status,
            'total_quantity' => (int) $this->total_quantity,
            // Tier 2 stores product-point equivalents for audit but never moves the wallet.
            'total_points' => (int) $this->tier === 3 ? (int) $this->total_points : 0,
            'product_points' => (int) $this->total_points,
            'affects_wallet_points' => (int) $this->tier === 3,
            'note' => $this->note,
            'from_user' => $this->when($this->relationLoaded('fromUser') && $this->fromUser, fn () => [
                'id' => $this->fromUser->id,
                'name' => $this->fromUser->name,
                'brand_name' => $this->fromUser->brand_name,
            ]),
            'to_user' => $this->when($this->relationLoaded('toUser') && $this->toUser, fn () => [
                'id' => $this->toUser->id,
                'name' => $this->toUser->name,
                'brand_name' => $this->toUser->brand_name,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'invoice_item_id' => (int) $item->invoice_item_id,
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'product_name' => localized_name($item->product, 'name', $item->product?->name ?? 'منتج'),
                'quantity' => (int) $item->quantity,
                'points_value' => (int) $item->points_value,
            ])->values()),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

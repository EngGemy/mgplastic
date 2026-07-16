<?php

namespace App\Http\Resources\Plumber;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivedDistributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $trader = $this->fromUser;
        $traderName = $trader?->brand_name ?: $trader?->name;

        $items = $this->whenLoaded('items', function () {
            return $this->items->map(function ($item) {
                $product = $item->invoiceItem?->product;

                return [
                    'id' => $item->id,
                    'quantity' => (int) $item->quantity,
                    'points_value' => (int) $item->points_value,
                    'product' => $product ? [
                        'id' => $product->id,
                        'name' => localized_name($product, 'name', 'منتج'),
                        'sku' => $product->sku ?? null,
                    ] : null,
                    'invoice_item' => $item->invoiceItem ? [
                        'id' => $item->invoiceItem->id,
                        'points_per_unit' => (float) ($item->invoiceItem->points_per_unit ?? 0),
                    ] : null,
                ];
            })->values();
        });

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'tier' => (int) $this->tier,
            'status' => $this->status,
            'status_label' => match ($this->status) {
                'confirmed' => 'مؤكد — جارٍ معالجة النقاط',
                'points_awarded' => 'تم إضافة النقاط للمحفظة',
                default => $this->status,
            },
            'confirmed_at' => $this->confirmed_at,
            'points_awarded_at' => $this->points_awarded_at,
            'total_points' => $this->whenLoaded('items', fn () => (int) $this->items->sum('points_value')),
            'total_quantity' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity')),

            // التاجر القطاعي (من وزّع للسباك)
            'trader_id' => $this->from_user_id,
            'trader_name' => $traderName,
            'trader' => $trader ? [
                'id' => $trader->id,
                'name' => $trader->name,
                'brand_name' => $trader->brand_name,
                'display_name' => $traderName,
                'phone' => $trader->phone,
                'network_code' => $trader->network_code,
                'profile_photo_url' => $trader->profile_photo_url,
            ] : null,

            // توافق خلفي
            'from_user' => $trader ? [
                'id' => $trader->id,
                'name' => $trader->name,
                'brand_name' => $trader->brand_name,
            ] : null,

            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'number' => $this->invoice->number,
                'status' => $this->invoice->status,
                'approved_at' => $this->invoice->approved_at,
                'attachment_path' => $this->invoice->attachment_path,
            ] : null),

            'items' => $items,
        ];
    }
}

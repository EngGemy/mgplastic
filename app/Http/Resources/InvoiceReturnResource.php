<?php

namespace App\Http\Resources;

use App\Support\InvoiceReturnStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceReturnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = InvoiceReturnStatus::normalize($this->status);
        $actionMeta = $this->availableActionsWithMeta($request);

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
            'status' => $status,
            'status_label' => InvoiceReturnStatus::label($status),
            'status_color' => InvoiceReturnStatus::color($status),
            'available_actions' => $actionMeta['actions'],
            'blocked_actions' => $actionMeta['blocked'],
            'total_quantity' => (int) $this->total_quantity,
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

    /**
     * @return array{actions: list<string>, blocked: list<array{action:string, reason:string}>}
     */
    protected function availableActionsWithMeta(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return ['actions' => [], 'blocked' => []];
        }

        $status = InvoiceReturnStatus::normalize($this->status);
        $isReceiver = (int) $this->to_user_id === (int) $user->id
            || in_array($user->role, ['super_admin', 'admin'], true);
        $isRequester = (int) $this->from_user_id === (int) $user->id
            || (int) ($this->created_by ?? 0) === (int) $user->id;

        $actions = [];
        $blocked = [];

        if ($status === InvoiceReturnStatus::PENDING) {
            if ($isReceiver) {
                $actions[] = 'approve';
                $actions[] = 'reject';
            } else {
                $blocked[] = ['action' => 'approve', 'reason' => 'قبول المرتجع متاح للمستلم (البائع الأصلي) فقط'];
                $blocked[] = ['action' => 'reject', 'reason' => 'رفض المرتجع متاح للمستلم (البائع الأصلي) فقط'];
            }

            if ($isRequester && ! $isReceiver) {
                $blocked[] = ['action' => 'cancel', 'reason' => 'إلغاء المرتجع غير متاح حالياً — انتظر رد المستلم'];
            }
        }

        return ['actions' => $actions, 'blocked' => $blocked];
    }
}

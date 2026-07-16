<?php

namespace App\Http\Resources\Mobile;

use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalletTransaction */
class WalletTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $delta = (int) $this->points_delta;
        $reason = data_get($this->meta, 'reason');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'amount_cents' => (int) $this->amount_cents,
            'points_delta' => $delta,
            'points_delta_label' => $delta > 0
                ? '+'.number_format($delta).' نقطة'
                : ($delta < 0 ? number_format($delta).' نقطة' : '0 نقطة'),
            'is_credit' => $delta > 0,
            'description' => $this->description,
            'reason' => $reason,
            'reason_label' => $this->reasonLabel($reason),
            'meta' => $this->meta,
            'distribution_id' => data_get($this->meta, 'distribution_id'),
            'invoice_id' => data_get($this->meta, 'invoice_id'),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_formatted' => $this->created_at
                ? $this->created_at->timezone('Africa/Tripoli')->format('Y/m/d H:i')
                : null,
            'date' => $this->created_at?->timezone('Africa/Tripoli')->toDateString(),
            'time' => $this->created_at?->timezone('Africa/Tripoli')->format('H:i'),
        ];
    }

    protected function typeLabel(): string
    {
        return match ($this->type) {
            'credit' => 'إضافة نقاط',
            'debit' => 'خصم نقاط',
            'withdrawal' => 'سحب',
            'conversion', 'convert' => 'تحويل لنقد',
            'adjustment' => 'تعديل',
            default => (string) $this->type,
        };
    }

    protected function reasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'distribution_points' => 'نقاط من فاتورة توزيع',
            'invoice_return_out' => 'خصم مرتجع',
            'invoice_return_in' => 'استلام مرتجع',
            'invoice_approval' => 'اعتماد فاتورة',
            'order_delivery' => 'تسليم طلب',
            default => $reason,
        };
    }
}

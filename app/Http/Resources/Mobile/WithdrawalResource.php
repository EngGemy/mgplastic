<?php

namespace App\Http\Resources\Mobile;

use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WithdrawalRequest */
class WithdrawalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount_cents' => $this->amount_cents,
            'amount_formatted' => $this->formattedAmount(),
            'currency' => $this->currencyCode(),
            'method' => $this->method,
            'method_label' => $this->methodLabel(),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'details' => $this->details,
            'payout_summary' => $this->payoutDetailsSummary(),
            'receipt_number' => $this->receipt_number,
            'transfer_number' => $this->transfer_number,
            'payment_proof' => $this->paymentProofSummary(),
            'receipt_url' => $this->receiptUrl(),
            'receipt_download_url' => $this->receiptDownloadUrl(),
            'receipt_public_url' => $this->receiptPublicUrl(),
            'transfer_card' => $this->transferCard(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}

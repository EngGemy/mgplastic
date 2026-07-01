<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceItem;
use App\Models\User;

class WholesalerPointsSyncService
{
    public function __construct(
        protected DistributionService $distributions,
    ) {}

    public function syncForWholesaler(User $wholesaler): void
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            return;
        }

        Invoice::query()
            ->where('wholesale_distributor_id', $wholesaler->id)
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'incoming')
            ->where('status', 'approved')
            ->with('items')
            ->orderBy('id')
            ->each(fn (Invoice $invoice) => $this->ensureIncomingReceipt($invoice, $wholesaler));
    }

    public function ensureIncomingReceipt(Invoice $invoice, User $wholesaler): void
    {
        if (! $invoice->isWholesalePos() || $invoice->invoice_flow !== 'incoming') {
            return;
        }

        $distribution = $invoice->distributions()
            ->where('tier', 1)
            ->where('to_user_id', $wholesaler->id)
            ->first();

        if (! $distribution) {
            $fromUser = User::where('role', 'super_admin')->first()
                ?? User::where('role', 'admin')->first()
                ?? $wholesaler;

            $items = $invoice->items->map(fn (InvoiceItem $item) => [
                'invoice_item_id' => $item->id,
                'quantity' => $item->quantity,
            ])->all();

            $distribution = $this->distributions->createDistribution(
                invoice: $invoice,
                fromUser: $fromUser,
                toUser: $wholesaler,
                tier: 1,
                items: $items,
                skipCallerCheck: true,
            );
        }

        if ($distribution->status === 'draft') {
            $this->distributions->confirmDistribution($distribution->fresh(['items']));
        }
    }

    /**
     * بعد إصلاح DistributionService:
     * tier 1 = تسجيل توزيع فقط — لا نقاط تتحرك للموزع
     * هذه الـ method تبقى للتوافق لكن لا تفعل شيئاً
     */
    public function ensureTierOneWalletCredit(InvoiceDistribution $distribution): void
    {
        return;
    }
}

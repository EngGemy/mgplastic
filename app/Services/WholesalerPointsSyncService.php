<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
                ?? User::where('role', 'admin')->first();

            if (! $fromUser) {
                return;
            }

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

        $this->reconcileTierOne($invoice->fresh('items'), $distribution->fresh('items'));
    }

    /**
     * Keep the factory receipt line equal to the invoice line, so stock,
     * factory points, and later quantity edits stay the same number.
     */
    public function reconcileTierOne(Invoice $invoice, InvoiceDistribution $distribution): void
    {
        if ((int) $distribution->tier !== 1) {
            return;
        }

        $distribution->load('items');

        foreach ($invoice->items as $item) {
            $downstream = (int) InvoiceDistributionItem::query()
                ->where('invoice_item_id', $item->id)
                ->whereHas('distribution', fn ($q) => $q
                    ->where('invoice_id', $invoice->id)
                    ->where('tier', '>=', 2)
                    ->whereIn('status', ['draft', 'confirmed', 'points_awarded'])
                )
                ->sum(DB::raw('quantity - COALESCE(returned_quantity, 0)'));

            $target = max((int) $item->quantity, $downstream);
            $points = (int) floor($target * (float) $item->points_per_unit);
            $line = $distribution->items->firstWhere('invoice_item_id', $item->id);

            if (! $line) {
                InvoiceDistributionItem::query()->create([
                    'distribution_id' => $distribution->id,
                    'invoice_item_id' => $item->id,
                    'quantity' => $target,
                    'points_value' => $points,
                ]);

                continue;
            }

            if ((int) $line->quantity === $target && (int) $line->points_value === $points) {
                continue;
            }

            $line->forceFill([
                'quantity' => $target,
                'points_value' => $points,
            ])->save();
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

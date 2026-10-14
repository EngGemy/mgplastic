<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvoiceAdjustmentService
{
    public function committedQuantity(InvoiceItem $item): int
    {
        $sumTier = function (int $tier) use ($item): int {
            return (int) $item->distributionItems()
                ->whereHas('distribution', fn ($q) => $q
                    ->where('tier', $tier)
                    ->whereIn('status', ['draft', 'confirmed', 'points_awarded']))
                ->sum(DB::raw('quantity - COALESCE(returned_quantity, 0)'));
        };

        $sentToRetail = $sumTier(2);

        return $sentToRetail > 0 ? $sentToRetail : $sumTier(3);
    }

    public function updateQuantity(InvoiceItem $item, int $quantity): InvoiceItem
    {
        if ($quantity < 1) {
            throw new \DomainException('الكمية يجب أن تكون 1 على الأقل');
        }

        $committed = $this->committedQuantity($item);

        if ($quantity < $committed) {
            throw new \DomainException("لا يمكن إنقاص الكمية عن {$committed} لأن هذه الكمية موزّعة بالفعل");
        }

        $points = (int) floor($quantity * (float) $item->points_per_unit);

        $item->forceFill([
            'quantity' => $quantity,
            'total_points' => $points,
        ])->save();

        $item->load('invoice');
        $invoice = $item->invoice;
        $wholesalerId = (int) ($invoice?->wholesale_distributor_id ?? 0);

        if ($invoice && $invoice->isWholesalePos() && $invoice->invoice_flow === 'incoming' && $wholesalerId > 0) {
            $wholesaler = User::query()->find($wholesalerId);

            if ($wholesaler?->isWholesaleDistributor()) {
                app(WholesalerPointsSyncService::class)->ensureIncomingReceipt($invoice->fresh('items'), $wholesaler);
            }
        }

        return $item->fresh();
    }

    public function deleteInvoice(Invoice $invoice): void
    {
        if ($invoice->distributions()->whereIn('status', ['confirmed', 'points_awarded'])->exists()) {
            throw new \DomainException('لا يمكن حذف الفاتورة بعد تأكيد توزيع كميات منها');
        }

        if (Invoice::query()->where('parent_invoice_id', $invoice->id)->exists()) {
            throw new \DomainException('لا يمكن حذف الفاتورة لأن لها فواتير فرعية');
        }

        $invoice->distributions()->where('status', 'draft')->delete();
        $invoice->delete();
    }
}

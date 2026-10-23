<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

class OutgoingInvoiceService
{
    /**
     * Create a document invoice for tier 2 (جملة→قطاعي) or tier 3 (قطاعي→سباك).
     * Snapshots line items so Filament / PDF show البنود.
     */
    public function createFromDistribution(InvoiceDistribution $distribution): Invoice
    {
        $tier = (int) $distribution->tier;
        if (! in_array($tier, [2, 3], true)) {
            throw new \DomainException('فقط توزيعات الجملة→القطاعي أو القطاعي→السباك تُنشئ فاتورة صادرة');
        }

        $distribution->loadMissing(['invoice', 'items.invoiceItem.product', 'fromUser', 'toUser']);

        $existing = Invoice::query()
            ->where('source_distribution_id', $distribution->id)
            ->first();

        if ($existing) {
            $this->syncLineItemsFromDistribution($existing, $distribution);
            $this->reconcileLineTotals($existing);

            return $existing->fresh(['items']);
        }

        return DB::transaction(function () use ($distribution, $tier) {
            $numberType = $tier === 2 ? 'wholesale_out' : 'plumber_receipt';
            [$serial, $number] = app(InvoiceNumberService::class)->reserveNext($numberType);
            $parent = $distribution->invoice;

            $payload = [
                'serial_number' => $serial,
                'number' => $number,
                'invoice_type' => 'wholesale_pos',
                'invoice_flow' => 'outgoing',
                'parent_invoice_id' => $parent?->id,
                'source_distribution_id' => $distribution->id,
                'subtotal_cents' => 0,
                'tax_cents' => 0,
                'total_cents' => 0,
                'currency' => 'LYD',
                'attachment_path' => null,
                'status' => $distribution->status === 'draft' ? 'pending_review' : 'approved',
                'approved_at' => $distribution->status !== 'draft' ? ($distribution->confirmed_at ?? now()) : null,
                'reviewed_by' => null,
                'issued_by' => $distribution->from_user_id,
                'points_awarded' => 0,
                'profit_percent' => null,
            ];

            if ($tier === 2) {
                $payload['wholesale_distributor_id'] = $distribution->from_user_id;
                $payload['counterparty_user_id'] = $distribution->to_user_id;
                $payload['plumber_id'] = null;
                $payload['vendor_store_id'] = null;
            } else {
                $payload['wholesale_distributor_id'] = null;
                $payload['counterparty_user_id'] = $distribution->to_user_id;
                $payload['plumber_id'] = $distribution->to_user_id;
                $payload['vendor_store_id'] = null;
            }

            $invoice = Invoice::create($payload);
            $this->syncLineItemsFromDistribution($invoice, $distribution);
            $this->reconcileLineTotals($invoice);

            return $invoice->fresh(['items']);
        });
    }

    /**
     * Copy distribution lines onto the document invoice (idempotent create).
     * Line total ALWAYS = floor(qty × lot points_per_unit).
     */
    public function syncLineItemsFromDistribution(Invoice $invoice, ?InvoiceDistribution $distribution = null): void
    {
        $distribution ??= $invoice->sourceDistribution;
        if (! $distribution) {
            return;
        }

        $distribution->loadMissing(['items.invoiceItem']);

        if ($invoice->items()->exists()) {
            $this->reconcileLineTotals($invoice);

            return;
        }

        if ($distribution->items->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($invoice, $distribution) {
            foreach ($distribution->items as $di) {
                $parentItem = $di->invoiceItem;
                $productId = (int) ($parentItem?->product_id ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $qty = (int) $di->quantity;
                $ppu = max(0, (float) ($parentItem?->points_per_unit ?? 0));
                // Prefer lot rate math; if lot PPU is 0, derive from awarded points_value.
                if ($ppu <= 0 && $qty > 0 && (int) $di->points_value > 0) {
                    $ppu = round(((int) $di->points_value) / $qty, 4);
                }
                $linePoints = (int) floor($qty * $ppu);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'box_count' => $parentItem?->box_count,
                    'pieces_per_box' => $parentItem?->pieces_per_box,
                    'unit_price_cents' => (float) ($parentItem?->unit_price_cents ?? 0),
                    'points_per_unit' => $ppu,
                    'total_points' => $linePoints,
                ]);
            }
        });
    }

    /**
     * Fix qty × ppu ≠ total_points (and header points_awarded) on outgoing docs.
     */
    public function reconcileLineTotals(Invoice $invoice): void
    {
        $invoice->loadMissing(['items', 'sourceDistribution.items.invoiceItem']);

        if ($invoice->items->isEmpty()) {
            return;
        }

        $sum = 0;
        foreach ($invoice->items as $item) {
            $qty = (int) $item->quantity;
            $ppu = max(0, (float) $item->points_per_unit);
            $expected = (int) floor($qty * $ppu);

            if ((int) $item->total_points !== $expected) {
                $item->forceFill(['total_points' => $expected])->save();
            }

            $sum += $expected;
        }

        // Keep distribution points_value aligned with lot math for returns.
        $dist = $invoice->sourceDistribution;
        if ($dist) {
            foreach ($dist->items as $di) {
                $parent = $di->invoiceItem;
                $qty = (int) $di->quantity;
                $ppu = max(0, (float) ($parent?->points_per_unit ?? 0));
                $expected = (int) floor($qty * $ppu);
                if ($ppu > 0 && (int) $di->points_value !== $expected) {
                    $di->forceFill(['points_value' => $expected])->save();
                }
            }
        }

        if ((int) $invoice->points_awarded !== $sum) {
            $invoice->forceFill(['points_awarded' => $sum])->save();
        }
    }
}

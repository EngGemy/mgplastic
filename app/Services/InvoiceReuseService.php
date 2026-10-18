<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvoiceReuseService
{
    /**
     * Clone an invoice (same parties / type) so lines can be edited and products added.
     *
     * @param  array<int, array{product_id?:int, quantity?:int, points_per_unit?:float}>|null  $extraLines
     */
    public function reuse(Invoice $source, User $actor, ?array $extraLines = null): Invoice
    {
        return DB::transaction(function () use ($source, $actor, $extraLines) {
            $source->loadMissing('items');

            $clone = Invoice::query()->create([
                'invoice_type' => $source->invoice_type,
                'invoice_flow' => $source->invoice_flow ?? 'incoming',
                'parent_invoice_id' => $source->isWholesalePos() && $source->isIncoming()
                    ? null
                    : $source->parent_invoice_id,
                'counterparty_user_id' => $source->counterparty_user_id,
                'plumber_id' => $source->plumber_id,
                'vendor_store_id' => $source->vendor_store_id,
                'wholesale_distributor_id' => $source->wholesale_distributor_id,
                'subtotal_cents' => 0,
                'tax_cents' => 0,
                'total_cents' => 0,
                'currency' => $source->currency ?: 'LYD',
                'status' => $source->isWholesalePos() ? 'approved' : 'pending_review',
                'issued_by' => $actor->id,
                'points_awarded' => 0,
            ]);

            $clone->ensureNumber();

            foreach ($source->items as $item) {
                InvoiceItem::query()->create([
                    'invoice_id' => $clone->id,
                    'product_id' => $item->product_id,
                    'quantity' => (int) $item->quantity,
                    'unit_price_cents' => (int) ($item->unit_price_cents ?? 0),
                    'points_per_unit' => (float) $item->points_per_unit,
                    'total_points' => (int) $item->total_points,
                    'box_count' => $item->box_count ?? null,
                ]);
            }

            if (is_array($extraLines)) {
                foreach ($extraLines as $line) {
                    $productId = (int) ($line['product_id'] ?? 0);
                    $qty = (int) ($line['quantity'] ?? 0);
                    if ($productId <= 0 || $qty <= 0) {
                        continue;
                    }

                    $ppu = (float) ($line['points_per_unit'] ?? 0);
                    InvoiceItem::query()->create([
                        'invoice_id' => $clone->id,
                        'product_id' => $productId,
                        'quantity' => $qty,
                        'unit_price_cents' => 0,
                        'points_per_unit' => $ppu,
                        'total_points' => (int) floor($qty * $ppu),
                    ]);
                }
            }

            $clone->load('items');
            $clone->update([
                'points_awarded' => (int) $clone->items->sum('total_points'),
            ]);

            return $clone->fresh(['items.product.translations']);
        });
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OutgoingInvoiceService
{
    public function createFromDistribution(InvoiceDistribution $distribution): Invoice
    {
        if ((int) $distribution->tier !== 2) {
            throw new \DomainException('فقط توزيعات طبقة الجملة → القطاعي تُنشئ فاتورة صادرة');
        }

        $distribution->loadMissing(['invoice', 'items', 'fromUser', 'toUser']);

        $existing = Invoice::query()
            ->where('source_distribution_id', $distribution->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $parent = $distribution->invoice;
        $points = (int) $distribution->items->sum('points_value');
        $quantity = (int) $distribution->items->sum('quantity');

        return DB::transaction(function () use ($distribution, $parent, $points, $quantity) {
            [$serial, $number] = app(InvoiceNumberService::class)->reserveNext('wholesale_out');

            return Invoice::create([
                'serial_number' => $serial,
                'number' => $number,
                'invoice_type' => 'wholesale_pos',
                'invoice_flow' => 'outgoing',
                'parent_invoice_id' => $parent?->id,
                'source_distribution_id' => $distribution->id,
                'wholesale_distributor_id' => $distribution->from_user_id,
                'counterparty_user_id' => $distribution->to_user_id,
                'plumber_id' => null,
                'vendor_store_id' => null,
                'subtotal_cents' => 0,
                'tax_cents' => 0,
                'total_cents' => 0,
                'currency' => 'LYD',
                'attachment_path' => null,
                'status' => $distribution->status === 'draft' ? 'pending_review' : 'approved',
                'approved_at' => $distribution->status !== 'draft' ? ($distribution->confirmed_at ?? now()) : null,
                'reviewed_by' => null,
                'issued_by' => $distribution->from_user_id,
                'points_awarded' => $points,
                'profit_percent' => null,
            ]);
        });
    }
}

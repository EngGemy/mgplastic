<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceNumberService
{
    /** يحجز الرقم التالي داخل transaction — يُرجع [serial, number]. */
    public function reserveNext(string $invoiceType = 'plumber_receipt'): array
    {
        $serial = $this->nextSerial();

        return [$serial, $this->formatNumber($serial, $invoiceType)];
    }

    /**
     * يخصّص الرقم التسلسلي الأساسي ورقم الفاتورة المعروض.
     * الرقم التسلسلي (serial_number) هو المرجع الثابت في كل النظام.
     */
    public function assign(Invoice $invoice): Invoice
    {
        if ($invoice->serial_number && $invoice->number) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice) {
            [$serial, $number] = $this->reserveNext($invoice->invoice_type ?? 'plumber_receipt');

            $invoice->update([
                'serial_number' => $serial,
                'number' => $number,
            ]);

            return $invoice->fresh();
        });
    }

    /** معاينة الرقم القادم (بدون حجز). */
    public function previewNext(?string $invoiceType = 'wholesale_pos'): string
    {
        $next = ((int) Invoice::max('serial_number')) + 1;

        return $this->formatNumber($next, $invoiceType ?? 'wholesale_pos');
    }

    public function formatNumber(int $serial, string $invoiceType): string
    {
        $prefix = match ($invoiceType) {
            'wholesale_pos' => 'MG-J',
            'wholesale_out' => 'MG-C',
            default => 'MG-S',
        };
        $year = now()->format('Y');

        return sprintf('%s-%s-%06d', $prefix, $year, $serial);
    }

    private function nextSerial(): int
    {
        $max = Invoice::query()->lockForUpdate()->max('serial_number');

        return ((int) $max) + 1;
    }
}

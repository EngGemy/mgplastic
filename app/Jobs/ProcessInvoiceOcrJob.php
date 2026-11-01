<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InvoiceOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInvoiceOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(
        public Invoice $invoice,
    ) {
        $this->onQueue('ocr');
    }

    public function handle(InvoiceOcrService $ocr): void
    {
        $ocr->process($this->invoice);
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('[OCR] job failed', [
            'invoice_id' => $this->invoice->id,
            'error' => $e?->getMessage(),
        ]);

        $this->invoice->forceFill([
            'ocr_status' => 'failed',
            'ocr_error' => mb_substr($e?->getMessage() ?: 'فشل طابور قراءة الفاتورة', 0, 500),
        ])->save();
    }
}

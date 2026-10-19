<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\WhatsAppBusinessAlerts;

class InvoiceObserver
{
    public function __construct(
        protected WhatsAppBusinessAlerts $alerts,
    ) {}

    public function created(Invoice $invoice): void
    {
        $this->alerts->invoiceCreated($invoice);
    }
}

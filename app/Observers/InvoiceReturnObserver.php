<?php

namespace App\Observers;

use App\Models\InvoiceReturn;
use App\Services\WhatsAppBusinessAlerts;
use App\Support\InvoiceReturnStatus;

class InvoiceReturnObserver
{
    public function __construct(
        protected WhatsAppBusinessAlerts $alerts,
    ) {}

    public function created(InvoiceReturn $return): void
    {
        $this->alerts->returnCreated($return);
    }

    public function updated(InvoiceReturn $return): void
    {
        if (! $return->wasChanged('status')) {
            return;
        }

        $from = (string) $return->getOriginal('status');
        $to = (string) $return->status;
        $normalized = InvoiceReturnStatus::normalize($to);

        if (! in_array($normalized, [InvoiceReturnStatus::APPROVED, InvoiceReturnStatus::REJECTED], true)) {
            return;
        }

        if ($from === $to) {
            return;
        }

        $this->alerts->returnStatusChanged($return, $from, $to);
    }
}

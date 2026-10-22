<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\Notifications\DomainEventNotifier;
use App\Services\WhatsAppBusinessAlerts;

class InvoiceObserver
{
    public function __construct(
        protected WhatsAppBusinessAlerts $alerts,
        protected DomainEventNotifier $notifications,
    ) {}

    public function created(Invoice $invoice): void
    {
        $this->alerts->invoiceCreated($invoice);

        if ($invoice->status === 'pending_review' || $invoice->status === null) {
            $this->notifications->invoiceSubmitted($invoice);
        }
    }

    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status')) {
            return;
        }

        $to = (string) $invoice->status;
        $from = (string) $invoice->getOriginal('status');

        if ($from === $to) {
            return;
        }

        if ($to === 'approved') {
            $this->notifications->invoiceApproved($invoice);

            $points = (int) ($invoice->points_awarded ?? 0);
            if ($points > 0 && $invoice->plumber) {
                $this->notifications->pointsAwarded($invoice->plumber, $points, [
                    'invoice_id' => $invoice->id,
                    'entity_id' => $invoice->id,
                ]);
            }

            return;
        }

        if ($to === 'rejected') {
            $reason = data_get($invoice->rejection_reason, 'ar')
                ?: data_get($invoice->rejection_reason, 'en')
                ?: data_get($invoice->rejection_reason, 'message');

            $this->notifications->invoiceRejected($invoice, is_string($reason) ? $reason : null);
        }
    }
}

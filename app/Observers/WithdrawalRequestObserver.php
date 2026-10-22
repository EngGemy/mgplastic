<?php

namespace App\Observers;

use App\Models\WithdrawalRequest;
use App\Services\Notifications\DomainEventNotifier;
use App\Services\WhatsAppBusinessAlerts;

class WithdrawalRequestObserver
{
    public function __construct(
        protected WhatsAppBusinessAlerts $alerts,
        protected DomainEventNotifier $notifications,
    ) {}

    public function created(WithdrawalRequest $withdrawal): void
    {
        if ($withdrawal->status === 'pending' || $withdrawal->status === null) {
            $this->alerts->withdrawalCreated($withdrawal);
            // Push via WithdrawalRequestService::notifySubmitted when that path is used;
            // also notify here so API/Filament creates are covered once.
            $this->notifications->withdrawalRequested($withdrawal);
        }
    }

    public function updated(WithdrawalRequest $withdrawal): void
    {
        if (! $withdrawal->wasChanged('status')) {
            return;
        }

        $from = (string) $withdrawal->getOriginal('status');
        $to = (string) $withdrawal->status;

        if ($from === $to || $to === '') {
            return;
        }

        $this->alerts->withdrawalStatusChanged($withdrawal, $from, $to);

        // Paid/rejected also notified from WithdrawalRequestService — skip duplicate when
        // the service already fired (idempotency key on entity_id+type+user handles it).
        if ($to === 'paid') {
            $this->notifications->withdrawalPaid($withdrawal);
        } elseif ($to === 'rejected') {
            $reason = data_get($withdrawal->rejection_reason, 'ar')
                ?: data_get($withdrawal->rejection_reason, 'en');
            $this->notifications->withdrawalRejected($withdrawal, is_string($reason) ? $reason : null);
        }
    }
}

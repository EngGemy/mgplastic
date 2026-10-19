<?php

namespace App\Observers;

use App\Models\WithdrawalRequest;
use App\Services\WhatsAppBusinessAlerts;

class WithdrawalRequestObserver
{
    public function __construct(
        protected WhatsAppBusinessAlerts $alerts,
    ) {}

    public function created(WithdrawalRequest $withdrawal): void
    {
        if ($withdrawal->status === 'pending' || $withdrawal->status === null) {
            $this->alerts->withdrawalCreated($withdrawal);
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
    }
}

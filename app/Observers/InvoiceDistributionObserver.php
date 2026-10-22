<?php

namespace App\Observers;

use App\Models\InvoiceDistribution;
use App\Services\Notifications\DomainEventNotifier;
use App\Services\WhatsAppBusinessAlerts;

class InvoiceDistributionObserver
{
    public function __construct(
        protected WhatsAppBusinessAlerts $alerts,
        protected DomainEventNotifier $notifications,
    ) {}

    public function updated(InvoiceDistribution $distribution): void
    {
        if (! $distribution->wasChanged('status')) {
            return;
        }

        $to = (string) $distribution->status;
        $from = (string) $distribution->getOriginal('status');

        if ($from === $to) {
            return;
        }

        if ($to === 'confirmed') {
            $this->alerts->distributionConfirmed($distribution);
            $this->notifications->distributionConfirmed($distribution);

            return;
        }

        if ($to === 'points_awarded') {
            $points = (int) $distribution->items()->sum('points_value');
            $this->alerts->pointsAwarded($distribution, $points);
            $this->notifications->distributionPointsAwarded($distribution, $points);
        }
    }
}

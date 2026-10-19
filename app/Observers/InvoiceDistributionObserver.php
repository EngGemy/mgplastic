<?php

namespace App\Observers;

use App\Models\InvoiceDistribution;
use App\Services\WhatsAppBusinessAlerts;

class InvoiceDistributionObserver
{
    public function __construct(
        protected WhatsAppBusinessAlerts $alerts,
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

            return;
        }

        if ($to === 'points_awarded') {
            $points = (int) $distribution->items()->sum('points_value');
            $this->alerts->pointsAwarded($distribution, $points);
        }
    }
}

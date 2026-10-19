<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\WhatsAppBusinessAlerts;

class OrderObserver
{
    public function __construct(
        protected WhatsAppBusinessAlerts $alerts,
    ) {}

    public function created(Order $order): void
    {
        $this->alerts->orderCreated($order);
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $from = (string) $order->getOriginal('status');
        $to = (string) $order->status;

        if ($from === $to || $to === '') {
            return;
        }

        $this->alerts->orderStatusChanged($order, $from, $to);
    }
}

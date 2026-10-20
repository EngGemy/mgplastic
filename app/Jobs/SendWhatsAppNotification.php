<?php

namespace App\Jobs;

use App\Services\WhatsAppNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function __construct(
        public array $messages,
    ) {
        $this->onQueue((string) config('services.waha.queue', 'whatsapp'));
    }

    public function handle(WhatsAppNotifier $notifier): void
    {
        try {
            $notifier->deliver($this->messages);
        } catch (\Throwable) {
            // Never break the queue worker / primary business flow.
        }
    }
}

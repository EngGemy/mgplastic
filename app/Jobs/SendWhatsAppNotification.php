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

    /**
     * @param  list<array{phone:string,text:string}>  $messages
     */
    public function __construct(
        public array $messages,
    ) {
        // Default "default" so `php artisan queue:work` picks jobs up.
        // Override with WAHA_QUEUE=whatsapp if you run a dedicated worker.
        $this->onQueue((string) config('services.waha.queue', 'default'));
    }

    public function handle(WhatsAppNotifier $notifier): void
    {
        $notifier->deliver($this->messages);
    }
}

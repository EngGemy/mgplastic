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

    /**
     * @param  list<array{phone:string,text:string}>  $messages
     */
    public function __construct(
        public array $messages,
    ) {}

    public function handle(WhatsAppNotifier $notifier): void
    {
        $notifier->deliver($this->messages);
    }
}

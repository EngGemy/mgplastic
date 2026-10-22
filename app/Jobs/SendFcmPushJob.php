<?php

namespace App\Jobs;

use App\Services\FcmPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFcmPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $token,
        public string $title,
        public string $body,
        public array $data = [],
    ) {
        $this->onQueue((string) config('services.fcm.queue', 'default'));
    }

    public function handle(FcmPushService $fcm): void
    {
        $fcm->sendToToken($this->token, $this->title, $this->body, $this->data);
    }
}

<?php

namespace App\Jobs;

use App\Services\WhatsAppNotifier;
use App\Support\WahaResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 20;

    public bool $failOnTimeout = true;

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
        if ($notifier->isBreakerOpen()) {
            $ttl = max(30, (int) config('services.waha.breaker_ttl', 300));
            Log::warning('[SendWhatsAppNotification] breaker open — releasing', [
                'delay' => $ttl,
                'attempt' => $this->attempts(),
            ]);
            $this->release($ttl);

            return;
        }

        $result = $notifier->deliverDetailed($this->messages);

        if ($result->ok) {
            return;
        }

        if ($result->reason === WahaResult::REASON_BREAKER_OPEN
            || $result->isSessionOrAuthFailure()) {
            $ttl = max(30, (int) config('services.waha.breaker_ttl', 300));
            $this->release($ttl);

            return;
        }

        Log::error('[SendWhatsAppNotification] deliver failed', [
            'reason' => $result->reason,
            'status' => $result->status,
            'detail' => $result->detail,
            'attempt' => $this->attempts(),
        ]);

        if ($this->attempts() >= $this->tries) {
            $this->fail(new \RuntimeException('WAHA deliver failed: '.$result->reason));
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('[SendWhatsAppNotification] permanently failed', [
            'error' => $e?->getMessage(),
            'message_count' => count($this->messages),
        ]);
    }
}

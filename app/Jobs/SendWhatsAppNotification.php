<?php

namespace App\Jobs;

use App\Models\WhatsAppLog;
use App\Services\HealthAlertService;
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

    /**
     * Prefer retryUntil() so breaker releases do not burn a small tries budget.
     * Kept high as a safety ceiling alongside retryUntil.
     */
    public int $tries = 100;

    public int $maxExceptions = 3;

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
        $this->maxExceptions = max(1, (int) config('monitoring.whatsapp_job_max_exceptions', 3));
    }

    public function retryUntil(): \DateTimeInterface
    {
        $hours = max(1, (int) config('monitoring.whatsapp_job_retry_hours', 12));

        return now()->addHours($hours);
    }

    public function handle(WhatsAppNotifier $notifier): void
    {
        $notifier->probeBreakerIfDue();

        if ($notifier->isBreakerOpen()) {
            $ttl = max(30, (int) config('services.waha.breaker_ttl', 300));
            Log::warning('[SendWhatsAppNotification] breaker open — releasing without failing', [
                'delay' => $ttl,
                'attempt' => $this->attempts(),
                'retry_until' => $this->retryUntil()->format(\DateTimeInterface::ATOM),
            ]);
            $this->release($ttl);

            return;
        }

        $result = $notifier->deliverDetailed($this->messages);

        if ($result->ok || $result->deliveryUnknown) {
            return;
        }

        if ($result->reason === WahaResult::REASON_BREAKER_OPEN
            || $result->isSessionOrAuthFailure()) {
            $ttl = max(30, (int) config('services.waha.breaker_ttl', 300));
            Log::warning('[SendWhatsAppNotification] session/auth/breaker — releasing', [
                'reason' => $result->reason,
                'delay' => $ttl,
                'attempt' => $this->attempts(),
            ]);
            $this->release($ttl);

            return;
        }

        Log::error('[SendWhatsAppNotification] deliver failed', [
            'reason' => $result->reason,
            'status' => $result->status,
            'detail' => $result->detail,
            'attempt' => $this->attempts(),
        ]);

        // Non-retryable delivery failure for this attempt — throw so maxExceptions applies.
        throw new \RuntimeException('WAHA deliver failed: '.$result->reason.($result->detail ? ' ('.$result->detail.')' : ''));
    }

    public function failed(?\Throwable $e): void
    {
        $reason = $e?->getMessage() ?: 'unknown';

        Log::error('[SendWhatsAppNotification] permanently failed', [
            'error' => $reason,
            'message_count' => count($this->messages),
        ]);

        foreach ($this->messages as $message) {
            WhatsAppLog::record([
                'event' => $message['event'] ?? null,
                'phone' => $message['phone'] ?? null,
                'recipient_type' => $message['recipient_type'] ?? 'customer',
                'status' => 'failed',
                'channel' => null,
                'reference_type' => $message['reference_type'] ?? null,
                'reference_id' => $message['reference_id'] ?? null,
                'error' => mb_substr('job_failed: '.$reason, 0, 500),
            ]);
        }

        try {
            app(HealthAlertService::class)->triggerImmediate(
                'فشل نهائي لمهمة واتساب',
                [
                    'error' => $reason,
                    'message_count' => count($this->messages),
                ]
            );
        } catch (\Throwable $alertError) {
            Log::warning('[SendWhatsAppNotification] health alert failed', [
                'error' => $alertError->getMessage(),
            ]);
        }
    }
}

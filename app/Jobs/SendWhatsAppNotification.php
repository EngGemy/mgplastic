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
use Illuminate\Support\Carbon;
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

        $queuedAt = now()->toIso8601String();
        foreach ($this->messages as $i => $message) {
            if (empty($message['queued_at'])) {
                $this->messages[$i]['queued_at'] = $queuedAt;
            }
        }
    }

    public function retryUntil(): \DateTimeInterface
    {
        $hours = max(1, (int) config('monitoring.whatsapp_job_retry_hours', 12));

        return now()->addHours($hours);
    }

    public function handle(WhatsAppNotifier $notifier): void
    {
        [$fresh, $expired] = $this->partitionByFreshness($this->messages);

        foreach ($expired as $message) {
            $this->logExpired($message);
        }

        if ($fresh === []) {
            Log::info('[SendWhatsAppNotification] all messages expired — skipping send');

            return;
        }

        $otp = [];
        $other = [];
        foreach ($fresh as $message) {
            if ($this->isOtpMessage($message)) {
                $otp[] = $message;
            } else {
                $other[] = $message;
            }
        }

        $notifier->probeBreakerIfDue();

        if ($notifier->isBreakerOpen()) {
            // OTP must never wait behind the breaker — fail fast so the user can retry / use SMS.
            foreach ($otp as $message) {
                WhatsAppLog::record([
                    'event' => $message['event'] ?? 'otp',
                    'phone' => $message['phone'] ?? null,
                    'recipient_type' => $message['recipient_type'] ?? 'otp',
                    'status' => 'failed',
                    'channel' => null,
                    'reference_type' => $message['reference_type'] ?? null,
                    'reference_id' => $message['reference_id'] ?? null,
                    'error' => 'breaker_open_otp_fail_fast',
                ]);
            }

            if ($other === []) {
                Log::warning('[SendWhatsAppNotification] breaker open — OTP fail-fast, nothing to release');

                return;
            }

            $ttl = max(30, (int) config('services.waha.breaker_ttl', 300));
            $this->messages = $other;
            Log::warning('[SendWhatsAppNotification] breaker open — releasing non-OTP only', [
                'delay' => $ttl,
                'attempt' => $this->attempts(),
                'otp_failed' => count($otp),
                'non_otp' => count($other),
            ]);
            $this->release($ttl);

            return;
        }

        $result = $notifier->deliverDetailed($fresh);

        if ($result->ok || $result->deliveryUnknown) {
            return;
        }

        if ($result->reason === WahaResult::REASON_BREAKER_OPEN
            || $result->isSessionOrAuthFailure()) {
            // Re-check OTP fail-fast if breaker opened mid-deliver.
            $stillFresh = $fresh;
            $otpLeft = array_values(array_filter($stillFresh, fn ($m) => $this->isOtpMessage($m)));
            $otherLeft = array_values(array_filter($stillFresh, fn ($m) => ! $this->isOtpMessage($m)));

            foreach ($otpLeft as $message) {
                WhatsAppLog::record([
                    'event' => $message['event'] ?? 'otp',
                    'phone' => $message['phone'] ?? null,
                    'recipient_type' => $message['recipient_type'] ?? 'otp',
                    'status' => 'failed',
                    'error' => 'breaker_open_otp_fail_fast',
                    'reference_type' => $message['reference_type'] ?? null,
                    'reference_id' => $message['reference_id'] ?? null,
                ]);
            }

            if ($otherLeft === []) {
                return;
            }

            $ttl = max(30, (int) config('services.waha.breaker_ttl', 300));
            $this->messages = $otherLeft;
            Log::warning('[SendWhatsAppNotification] session/auth/breaker — releasing non-OTP', [
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

        throw new \RuntimeException('WAHA deliver failed: '.$result->reason.($result->detail ? ' ('.$result->detail.')' : ''));
    }

    public function failed(?\Throwable $e): void
    {
        $reason = $e?->getMessage() ?: 'unknown';

        // Stale expiry is handled in handle() and must not raise failure alerts.
        if (str_contains($reason, 'stale_after_outage')) {
            return;
        }

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

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public function partitionByFreshness(array $messages): array
    {
        $fresh = [];
        $expired = [];

        foreach ($messages as $message) {
            if ($this->isStale($message)) {
                $expired[] = $message;
            } else {
                $fresh[] = $message;
            }
        }

        return [$fresh, $expired];
    }

    /** @param  array<string, mixed>  $message */
    public function isStale(array $message): bool
    {
        $queuedAtRaw = $message['queued_at'] ?? null;
        if (! is_string($queuedAtRaw) || $queuedAtRaw === '') {
            return false;
        }

        try {
            $queuedAt = Carbon::parse($queuedAtRaw);
        } catch (\Throwable) {
            return false;
        }

        $event = (string) ($message['event'] ?? '');
        if ($event === '' && $this->isOtpMessage($message)) {
            $event = 'otp';
        }

        $rules = (array) config('monitoring.whatsapp_message_max_age', []);
        $rule = $rules[$event] ?? $rules['default'] ?? 43200;

        if ($rule === 'end_of_day') {
            return now()->greaterThan($queuedAt->copy()->endOfDay());
        }

        $seconds = max(60, (int) $rule);

        return $queuedAt->copy()->addSeconds($seconds)->lessThan(now());
    }

    /** @param  array<string, mixed>  $message */
    protected function isOtpMessage(array $message): bool
    {
        if (! empty($message['is_otp'])) {
            return true;
        }

        $recipient = (string) ($message['recipient_type'] ?? '');
        $event = (string) ($message['event'] ?? '');

        return $recipient === 'otp' || in_array($event, ['otp', 'otp_card'], true);
    }

    /** @param  array<string, mixed>  $message */
    protected function logExpired(array $message): void
    {
        WhatsAppLog::record([
            'event' => $message['event'] ?? null,
            'phone' => $message['phone'] ?? null,
            'recipient_type' => $message['recipient_type'] ?? 'customer',
            'status' => 'expired',
            'channel' => null,
            'reference_type' => $message['reference_type'] ?? null,
            'reference_id' => $message['reference_id'] ?? null,
            'error' => 'stale_after_outage',
        ]);
    }
}

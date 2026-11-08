<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\WhatsAppLog;
use App\Support\HealthCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SystemHealthService
{
    public function __construct(
        protected WhatsAppNotifier $whatsApp,
    ) {}

    /**
     * @return list<HealthCheck>
     */
    public function checks(bool $liveWahaSession = true): array
    {
        $this->whatsApp->probeBreakerIfDue();

        return [
            $this->checkWahaBreaker(),
            $liveWahaSession ? $this->checkWahaSession() : $this->cachedWahaSessionCheck(),
            $this->checkQueueWorker(),
            $this->checkFailedJobs24h(),
            $this->checkWhatsAppFailures(),
            $this->checkPushFcm(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, status: string, value: mixed, details: ?string, checked_at: string}>
     */
    public function checksAsArrays(bool $liveWahaSession = true): array
    {
        return array_map(fn (HealthCheck $c) => $c->toArray(), $this->checks($liveWahaSession));
    }

    /**
     * @return list<HealthCheck>
     */
    public function problems(bool $liveWahaSession = true): array
    {
        return array_values(array_filter(
            $this->checks($liveWahaSession),
            fn (HealthCheck $c) => $c->isProblem()
        ));
    }

    /**
     * Cached summary for admin banner (avoids WAHA + heavy queries on every request).
     *
     * @return list<array{key: string, label: string, status: string, value: mixed, details: ?string, checked_at: string}>
     */
    public function bannerProblems(): array
    {
        $ttl = max(5, (int) config('monitoring.health_banner_cache_seconds', 30));

        return Cache::remember('monitoring:banner_problems', $ttl, function () {
            return array_map(
                fn (HealthCheck $c) => $c->toArray(),
                $this->problems(liveWahaSession: true)
            );
        });
    }

    public function forgetBannerCache(): void
    {
        Cache::forget('monitoring:banner_problems');
    }

    public function checkWahaBreaker(): HealthCheck
    {
        $open = $this->whatsApp->isBreakerOpen();
        $reason = $this->whatsApp->breakerReason();
        $openedAt = $this->whatsApp->breakerOpenedAt();

        if (! $open) {
            return HealthCheck::make([
                'key' => 'waha_breaker',
                'label' => 'قفل واتساب (Circuit Breaker)',
                'status' => 'ok',
                'value' => 'closed',
                'details' => 'مغلق',
            ]);
        }

        $since = $openedAt ? Carbon::parse($openedAt)->diffForHumans() : 'غير معروف';

        return HealthCheck::make([
            'key' => 'waha_breaker',
            'label' => 'قفل واتساب (Circuit Breaker)',
            'status' => 'critical',
            'value' => 'open',
            'details' => trim('مفتوح — السبب: '.($reason ?: 'غير معروف').' — منذ: '.$since),
        ]);
    }

    public function checkWahaSession(): HealthCheck
    {
        $cacheSeconds = max(10, (int) config('monitoring.waha_session_cache_seconds', 60));
        $payload = Cache::remember('monitoring:waha_session_check', $cacheSeconds, function () {
            return $this->fetchWahaSession();
        });

        return HealthCheck::make($payload);
    }

    public function cachedWahaSessionCheck(): HealthCheck
    {
        $cached = Cache::get('monitoring:waha_session_check');
        if (is_array($cached)) {
            return HealthCheck::make($cached);
        }

        return HealthCheck::make([
            'key' => 'waha_session',
            'label' => 'جلسة WAHA',
            'status' => 'warning',
            'value' => 'unknown',
            'details' => 'لا توجد نتيجة مخزنة بعد',
        ]);
    }

    /**
     * @return array{key: string, label: string, status: string, value: mixed, details: string, checked_at: string}
     */
    protected function fetchWahaSession(): array
    {
        $checkedAt = now()->toIso8601String();
        $base = rtrim(trim((string) config('services.waha.url')), '/');
        $session = trim((string) config('services.waha.session', ''));
        $timeout = max(2, (int) config('monitoring.waha_session_timeout', 5));

        if ($base === '' || $session === '') {
            return [
                'key' => 'waha_session',
                'label' => 'جلسة WAHA',
                'status' => 'critical',
                'value' => 'misconfigured',
                'details' => 'WAHA_URL أو WAHA_SESSION فارغ',
                'checked_at' => $checkedAt,
            ];
        }

        try {
            $response = Http::connectTimeout($timeout)
                ->timeout($timeout)
                ->withHeaders([
                    'X-Api-Key' => (string) config('services.waha.key'),
                    'Accept' => 'application/json',
                ])
                ->get("{$base}/api/sessions/{$session}");

            $http = $response->status();
            $json = $response->json();
            $sessionStatus = is_array($json)
                ? (string) ($json['status'] ?? $json['engine']['status'] ?? 'UNKNOWN')
                : 'UNKNOWN';

            $ok = $http === 200 && strtoupper($sessionStatus) === 'WORKING';

            return [
                'key' => 'waha_session',
                'label' => 'جلسة WAHA',
                'status' => $ok ? 'ok' : 'critical',
                'value' => [
                    'http' => $http,
                    'status' => $sessionStatus,
                ],
                'details' => "HTTP {$http} — status={$sessionStatus}",
                'checked_at' => $checkedAt,
            ];
        } catch (\Throwable $e) {
            Log::warning('[SystemHealth] waha_session probe failed', ['error' => $e->getMessage()]);

            return [
                'key' => 'waha_session',
                'label' => 'جلسة WAHA',
                'status' => 'critical',
                'value' => [
                    'http' => null,
                    'status' => 'ERROR',
                ],
                'details' => 'فشل الاتصال: '.$e->getMessage(),
                'checked_at' => $checkedAt,
            ];
        }
    }

    public function checkQueueWorker(): HealthCheck
    {
        $key = (string) config('monitoring.queue_heartbeat_key', 'monitoring:queue_worker_heartbeat');
        $heartbeat = Cache::get($key);
        $staleAfter = max(60, (int) config('monitoring.queue_heartbeat_stale_seconds', 300));
        $oldestCritical = max(60, (int) config('monitoring.queue_oldest_pending_critical_seconds', 600));

        $heartbeatAge = null;
        $heartbeatOk = false;
        if (is_string($heartbeat) && $heartbeat !== '') {
            try {
                $heartbeatAge = Carbon::parse($heartbeat)->diffInSeconds(now());
                $heartbeatOk = $heartbeatAge <= $staleAfter;
            } catch (\Throwable) {
                $heartbeatOk = false;
            }
        }

        $oldestPendingSeconds = null;
        try {
            $oldestAvailable = DB::table('jobs')->min('available_at');
            if ($oldestAvailable !== null) {
                $oldestPendingSeconds = max(0, now()->getTimestamp() - (int) $oldestAvailable);
            }
        } catch (\Throwable) {
            $oldestPendingSeconds = null;
        }

        $pendingCritical = $oldestPendingSeconds !== null && $oldestPendingSeconds > $oldestCritical;
        $status = 'ok';
        $details = [];

        if (! $heartbeatOk) {
            $status = 'critical';
            $details[] = $heartbeat
                ? 'آخر نبضة منذ '.($heartbeatAge ?? '?').' ثانية'
                : 'لا توجد نبضة من عامل الطابور';
        } else {
            $details[] = 'نبضة حديثة ('.($heartbeatAge ?? 0).'ث)';
        }

        if ($pendingCritical) {
            $status = 'critical';
            $details[] = 'أقدم مهمة معلّقة منذ '.$oldestPendingSeconds.' ثانية';
        } elseif ($oldestPendingSeconds !== null) {
            $details[] = 'أقدم معلّقة: '.$oldestPendingSeconds.'ث';
        } else {
            $details[] = 'لا مهام معلّقة';
        }

        return HealthCheck::make([
            'key' => 'queue_worker',
            'label' => 'عامل الطابور',
            'status' => $status,
            'value' => [
                'heartbeat_at' => $heartbeat,
                'heartbeat_age_seconds' => $heartbeatAge,
                'oldest_pending_seconds' => $oldestPendingSeconds,
            ],
            'details' => implode(' — ', $details),
        ]);
    }

    public function checkFailedJobs24h(): HealthCheck
    {
        $warning = max(1, (int) config('monitoring.failed_jobs_warning', 1));
        $critical = max(1, (int) config('monitoring.failed_jobs_critical', 5));
        $since = now()->subDay();

        $byQueue = [];
        $total = 0;
        $lastError = null;
        $lastFailedAt = null;

        try {
            $rows = DB::table('failed_jobs')
                ->where('failed_at', '>=', $since)
                ->orderByDesc('failed_at')
                ->get(['id', 'queue', 'exception', 'failed_at']);

            $total = $rows->count();
            $byQueue = $rows->groupBy('queue')->map->count()->all();

            $latest = $rows->first();
            if ($latest) {
                $lastFailedAt = (string) $latest->failed_at;
                $lastError = $this->extractRealFailedJobError((string) $latest->exception, (int) $latest->id);
            }
        } catch (\Throwable $e) {
            return HealthCheck::make([
                'key' => 'failed_jobs_24h',
                'label' => 'المهام الفاشلة (24س)',
                'status' => 'warning',
                'value' => null,
                'details' => 'تعذر القراءة: '.$e->getMessage(),
            ]);
        }

        $status = 'ok';
        if ($total >= $critical) {
            $status = 'critical';
        } elseif ($total >= $warning) {
            $status = 'warning';
        }

        $queueSummary = $byQueue === []
            ? 'لا فشل'
            : collect($byQueue)->map(fn ($c, $q) => "{$q}:{$c}")->implode(', ');

        return HealthCheck::make([
            'key' => 'failed_jobs_24h',
            'label' => 'المهام الفاشلة (24س)',
            'status' => $status,
            'value' => [
                'total' => $total,
                'by_queue' => $byQueue,
                'last_error' => $lastError,
                'last_failed_at' => $lastFailedAt,
            ],
            'details' => $total === 0
                ? 'لا فشل خلال 24 ساعة'
                : "{$total} فشل ({$queueSummary})".($lastError ? ' — آخر خطأ: '.$lastError : ''),
        ]);
    }

    public function checkWhatsAppFailures(): HealthCheck
    {
        $windowHours = max(1, (int) config('monitoring.whatsapp_failures_window_hours', 24));
        $noSentHours = max(1, (int) config('monitoring.whatsapp_no_sent_critical_hours', 6));
        $since = now()->subHours($windowHours);
        $sinceNoSent = now()->subHours($noSentHours);

        $failedCount = 0;
        $attemptCount = 0;
        $sentCount = 0;
        $lastSentAt = null;

        try {
            $failedCount = (int) WhatsAppLog::query()
                ->where('created_at', '>=', $since)
                ->where('status', 'failed')
                ->count();

            $attemptCount = (int) WhatsAppLog::query()
                ->where('created_at', '>=', $sinceNoSent)
                ->whereIn('status', ['failed', 'sent', 'fallback_text', 'pending', 'skipped_limit'])
                ->count();

            $sentCount = (int) WhatsAppLog::query()
                ->where('created_at', '>=', $sinceNoSent)
                ->whereIn('status', ['sent', 'fallback_text'])
                ->count();

            $lastSentAt = WhatsAppLog::query()
                ->whereIn('status', ['sent', 'fallback_text'])
                ->orderByDesc('created_at')
                ->value('created_at');
        } catch (\Throwable $e) {
            return HealthCheck::make([
                'key' => 'whatsapp_failures',
                'label' => 'فشل واتساب',
                'status' => 'warning',
                'value' => null,
                'details' => 'تعذر القراءة: '.$e->getMessage(),
            ]);
        }

        $status = 'ok';
        $details = "فشل خلال {$windowHours}س: {$failedCount}";

        if ($lastSentAt) {
            $details .= ' — آخر إرسال: '.Carbon::parse($lastSentAt)->diffForHumans();
        } else {
            $details .= ' — لا يوجد إرسال ناجح مسجّل';
        }

        if ($attemptCount > 0 && $sentCount === 0) {
            $status = 'critical';
            $details .= " — محاولات خلال {$noSentHours}س بدون أي إرسال ناجح";
        } elseif ($failedCount >= 5) {
            $status = 'critical';
        } elseif ($failedCount >= 1) {
            $status = 'warning';
        }

        return HealthCheck::make([
            'key' => 'whatsapp_failures',
            'label' => 'فشل واتساب',
            'status' => $status,
            'value' => [
                'failed_24h' => $failedCount,
                'attempts_window' => $attemptCount,
                'sent_window' => $sentCount,
                'last_sent_at' => $lastSentAt ? (string) $lastSentAt : null,
            ],
            'details' => $details,
        ]);
    }

    public function checkPushFcm(): HealthCheck
    {
        $staleMinutes = max(5, (int) config('monitoring.push_queued_stale_minutes', 30));
        $failedWindow = max(1, (int) config('monitoring.push_failed_window_hours', 24));

        $staleQueued = 0;
        $failed24h = 0;

        try {
            $staleQueued = (int) NotificationLog::query()
                ->where('status', NotificationLog::STATUS_QUEUED)
                ->where('created_at', '<=', now()->subMinutes($staleMinutes))
                ->count();

            $failed24h = (int) NotificationLog::query()
                ->where('status', NotificationLog::STATUS_FAILED)
                ->where('created_at', '>=', now()->subHours($failedWindow))
                ->count();
        } catch (\Throwable $e) {
            return HealthCheck::make([
                'key' => 'push_fcm',
                'label' => 'إشعارات FCM',
                'status' => 'warning',
                'value' => null,
                'details' => 'تعذر القراءة: '.$e->getMessage(),
            ]);
        }

        $status = 'ok';
        if ($staleQueued > 0 || $failed24h >= 10) {
            $status = 'critical';
        } elseif ($failed24h >= 1) {
            $status = 'warning';
        }

        return HealthCheck::make([
            'key' => 'push_fcm',
            'label' => 'إشعارات FCM',
            'status' => $status,
            'value' => [
                'queued_stale' => $staleQueued,
                'failed_24h' => $failed24h,
            ],
            'details' => "معلّق >{$staleMinutes}د: {$staleQueued} — فشل {$failedWindow}س: {$failed24h}",
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentFailedJobs(int $limit = 50): array
    {
        try {
            $rows = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit($limit)
                ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']);
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(function ($row) {
            $jobClass = $this->extractJobClass((string) $row->payload);
            $error = $this->extractRealFailedJobError((string) $row->exception, (int) $row->id, $jobClass);

            return [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'queue' => (string) $row->queue,
                'job_class' => $jobClass,
                'failed_at' => (string) $row->failed_at,
                'error' => $error,
            ];
        })->all();
    }

    public function extractJobClass(string $payload): string
    {
        $json = json_decode($payload, true);
        if (! is_array($json)) {
            return 'unknown';
        }

        $commandName = $json['displayName'] ?? null;
        if (is_string($commandName) && $commandName !== '') {
            return $commandName;
        }

        $command = $json['data']['commandName'] ?? null;
        if (is_string($command) && $command !== '') {
            return $command;
        }

        $serialized = $json['data']['command'] ?? null;
        if (is_string($serialized) && preg_match('/O:\d+:"([^"]+)"/', $serialized, $m)) {
            return $m[1];
        }

        return 'unknown';
    }

    public function extractRealFailedJobError(string $exception, int $failedJobId, ?string $jobClass = null): string
    {
        $firstLine = trim(strtok($exception, "\n") ?: $exception);
        $firstLine = mb_substr($firstLine, 0, 500);

        $isMaxAttempts = str_contains($exception, 'MaxAttemptsExceededException')
            || str_contains($firstLine, 'MaxAttemptsExceededException');

        if (! $isMaxAttempts) {
            return $firstLine;
        }

        // Prefer the latest whatsapp_logs.error for WhatsApp jobs.
        if ($jobClass === null || str_contains($jobClass, 'SendWhatsAppNotification')) {
            $waError = WhatsAppLog::query()
                ->whereNotNull('error')
                ->where('error', '!=', '')
                ->orderByDesc('created_at')
                ->value('error');

            if (is_string($waError) && $waError !== '') {
                return 'MaxAttemptsExceeded — آخر خطأ واتساب: '.$waError;
            }
        }

        return $firstLine;
    }
}

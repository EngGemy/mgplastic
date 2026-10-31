<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotification;
use App\Mail\WhatsAppLimitReachedMail;
use App\Models\WhatsAppLog;
use App\Support\WahaResult;
use App\Support\WhatsAppMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WhatsAppNotifier
{
    public const BREAKER_CACHE_KEY = 'waha:circuit_breaker';

    public const HEALTH_CACHE_KEY = 'waha:health_snapshot';

    public const LAST_SUCCESS_CACHE_KEY = 'waha:last_success_at';

    /**
     * Immediate send (OTP). Never fans out to admins. Never delayed / never counts toward daily limit.
     *
     * @param  string|array{url?:string,base64?:string,mimetype?:string,filename?:string}|null  $image
     */
    public function send(string $phone, string $text, string|array|null $image = null): bool
    {
        return $this->deliverDetailed([[
            'phone' => $phone,
            'text' => $text,
            'image' => $image,
            'event' => 'otp',
            'recipient_type' => 'otp',
            'is_otp' => true,
        ]], skipDelay: true)->ok;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function sendMany(array $messages): void
    {
        try {
            if (! $this->enabled()) {
                Log::warning('[WhatsAppNotifier] skipped — WHATSAPP_NOTIFY_ENABLED is false');

                return;
            }

            $prepared = $this->prepareMessages($messages);
            if ($prepared === []) {
                return;
            }

            // Default: always queue. Sync only when explicitly enabled OR queue driver is sync (tests).
            $forceSync = filter_var(config('services.waha.sync_alerts', false), FILTER_VALIDATE_BOOLEAN)
                || config('queue.default') === 'sync';

            if ($forceSync) {
                $this->deliverDetailed($prepared);

                return;
            }

            SendWhatsAppNotification::dispatch($prepared)->afterCommit();
        } catch (\Throwable $e) {
            Log::error('[WhatsAppNotifier] sendMany exception', ['error' => $e->getMessage()]);
        }
    }

    public function notifyAdmins(string $text, string|array|null $image = null, array $meta = []): void
    {
        $messages = [];
        foreach ($this->adminPhones() as $phone) {
            $messages[] = array_merge($meta, [
                'phone' => $phone,
                'text' => $text,
                'image' => $image,
                'recipient_type' => 'admin',
            ]);
        }
        $this->sendMany($messages);
    }

    /**
     * @param  list<string|null>  $userPhones
     */
    public function notifyUsersAndAdmins(array $userPhones, string $text, string|array|null $image = null, array $meta = []): void
    {
        $messages = [];
        if ($this->notifyCustomers()) {
            foreach ($userPhones as $phone) {
                if (filled($phone)) {
                    $messages[] = array_merge($meta, [
                        'phone' => (string) $phone,
                        'text' => $text,
                        'image' => $image,
                        'recipient_type' => 'customer',
                    ]);
                }
            }
        }
        foreach ($this->adminPhones() as $phone) {
            $messages[] = array_merge($meta, [
                'phone' => $phone,
                'text' => $text,
                'image' => $image,
                'recipient_type' => 'admin',
            ]);
        }
        $this->sendMany($messages);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function deliver(array $messages, bool $skipDelay = false): bool
    {
        return $this->deliverDetailed($messages, $skipDelay)->ok;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function deliverDetailed(array $messages, bool $skipDelay = false): WahaResult
    {
        try {
            $otpOnly = $messages !== [] && collect($messages)->every(
                fn ($m) => (bool) ($m['is_otp'] ?? (($m['recipient_type'] ?? '') === 'otp'))
            );

            if (! $this->enabled() && ! $otpOnly) {
                return WahaResult::failure(WahaResult::REASON_DISABLED);
            }

            if (! $this->enabled() && $otpOnly) {
                Log::warning('[WhatsAppNotifier] WHATSAPP_NOTIFY_ENABLED is false — attempting OTP anyway');
            }

            if ($this->isBreakerOpen()) {
                return WahaResult::failure(WahaResult::REASON_BREAKER_OPEN, null, 'circuit breaker open');
            }

            $prepared = $this->prepareMessages($messages);
            if ($prepared === []) {
                return WahaResult::failure(WahaResult::REASON_SKIPPED, null, 'no messages');
            }

            $baseUrl = rtrim(trim((string) config('services.waha.url')), '/');
            if ($baseUrl === '') {
                Log::error('[WhatsAppNotifier] WAHA_URL is empty');

                return WahaResult::failure(WahaResult::REASON_CONFIG, null, 'WAHA_URL empty');
            }

            $session = $this->sessionName();
            if ($session === '') {
                Log::error('[WhatsAppNotifier] WAHA_SESSION is empty');

                return WahaResult::failure(WahaResult::REASON_CONFIG, null, 'WAHA_SESSION empty');
            }

            $lastFailure = null;
            $isFirst = true;

            foreach ($prepared as $message) {
                $isOtp = (bool) ($message['is_otp'] ?? ($message['recipient_type'] ?? '') === 'otp');

                if (! $isFirst && ! $skipDelay && ! $isOtp) {
                    $this->sleepBetweenMessages();
                }
                $isFirst = false;

                if (! $isOtp && $this->dailyLimitReached()) {
                    $this->logAttempt($message, 'skipped_limit', null, 'Daily limit reached');
                    $this->maybeAlertDailyLimit();
                    $lastFailure = WahaResult::failure(WahaResult::REASON_SKIPPED, null, 'daily limit');

                    continue;
                }

                if (! $this->isValidInternational($message['phone'])) {
                    $this->logAttempt($message, 'skipped_invalid_phone', null, 'Invalid international phone');
                    $lastFailure = WahaResult::failure(WahaResult::REASON_INVALID_PHONE);

                    continue;
                }

                if ($this->shouldCoalesceSkip($message)) {
                    continue;
                }

                $result = $this->deliverOne($baseUrl, $session, $message, $isOtp);
                if ($result->ok) {
                    $this->markCoalesce($message);
                    Cache::put(self::LAST_SUCCESS_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

                    continue;
                }

                $lastFailure = $result;
                if ($result->isSessionOrAuthFailure() || $result->reason === WahaResult::REASON_BREAKER_OPEN) {
                    return $result;
                }
            }

            return $lastFailure ?? WahaResult::success();
        } catch (\Throwable $e) {
            Log::error('[WhatsAppNotifier] deliver exception', ['error' => $e->getMessage()]);
            $this->openBreaker('exception: '.$e->getMessage());

            return WahaResult::failure(WahaResult::REASON_TIMEOUT, null, $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $message */
    protected function deliverOne(string $baseUrl, string $session, array $message, bool $isOtp): WahaResult
    {
        $hasImage = $this->hasImagePayload($message['image'] ?? null);

        if ($hasImage) {
            $imageResult = $this->sendImage($baseUrl, $session, $message['phone'], $message['text'], $message['image'], $isOtp);
            if ($imageResult->ok) {
                $this->logAttempt($message, 'sent', 'image', null);

                return $imageResult;
            }

            // Session/auth/timeout/breaker: do NOT try url or text — stop the storm.
            if ($imageResult->isSessionOrAuthFailure() || $imageResult->reason === WahaResult::REASON_BREAKER_OPEN) {
                $this->logAttempt($message, 'failed', 'image', $imageResult->reason);

                return $imageResult;
            }

            // Media-specific failure only → one text fallback.
            Log::warning('[WhatsAppNotifier] sendImage media error — fallback sendText', [
                'phone' => $message['phone'],
                'reason' => $imageResult->reason,
            ]);
            $textResult = $this->sendText($baseUrl, $session, $message['phone'], $message['text'], $isOtp);
            if ($textResult->ok) {
                $this->logAttempt($message, 'fallback_text', 'text', null);

                return $textResult;
            }
            $this->logAttempt($message, 'failed', 'text', $textResult->reason);

            return $textResult;
        }

        $textResult = $this->sendText($baseUrl, $session, $message['phone'], $message['text'], $isOtp);
        $this->logAttempt(
            $message,
            $textResult->ok ? 'sent' : 'failed',
            'text',
            $textResult->ok ? null : $textResult->reason
        );

        return $textResult;
    }

    public function isBreakerOpen(): bool
    {
        return Cache::has(self::BREAKER_CACHE_KEY);
    }

    public function breakerReason(): ?string
    {
        $payload = Cache::get(self::BREAKER_CACHE_KEY);

        return is_array($payload) ? (string) ($payload['reason'] ?? 'open') : (is_string($payload) ? $payload : null);
    }

    public function breakerOpenedAt(): ?string
    {
        $payload = Cache::get(self::BREAKER_CACHE_KEY);

        return is_array($payload) ? ($payload['opened_at'] ?? null) : null;
    }

    public function resetBreaker(): void
    {
        Cache::forget(self::BREAKER_CACHE_KEY);
    }

    public function openBreaker(string $reason): void
    {
        $ttl = max(30, (int) config('services.waha.breaker_ttl', 300));
        $already = $this->isBreakerOpen();
        Cache::put(self::BREAKER_CACHE_KEY, [
            'reason' => $reason,
            'opened_at' => now()->toIso8601String(),
        ], $ttl);

        if (! $already) {
            Log::error('WAHA DOWN: '.$reason, [
                'breaker_ttl' => $ttl,
            ]);
        }
    }

    /** Cached health snapshot for admin UI /deep health (never calls WAHA live). */
    public function cachedHealthSnapshot(): array
    {
        return Cache::get(self::HEALTH_CACHE_KEY, [
            'ok' => null,
            'reason' => 'unknown',
            'checked_at' => null,
        ]);
    }

    public function enabled(): bool
    {
        return (bool) config('services.waha.enabled');
    }

    public function notifyCustomers(): bool
    {
        return (bool) config('services.waha.notify_customers', true);
    }

    public function sessionName(): string
    {
        return trim((string) config('services.waha.session', ''));
    }

    public function normalizePhone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        while (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '218') || str_starts_with($digits, '20')) {
            return $digits;
        }
        $len = strlen($digits);
        if (str_starts_with($digits, '0') && $len === 10) {
            return '218'.substr($digits, 1);
        }
        if (str_starts_with($digits, '0') && $len === 11) {
            return '20'.substr($digits, 1);
        }
        $defaultCc = preg_replace('/\D+/', '', (string) config('services.waha.default_country_code', '218')) ?: '218';
        if (! str_starts_with($digits, '0') && $len >= 8 && $len <= 10) {
            return $defaultCc.$digits;
        }

        return $digits;
    }

    /** Libya 218 + 9 digits, Egypt 20 + 10 digits, or other E.164-ish 10–15 digits with country code. */
    public function isValidInternational(string $digits): bool
    {
        if (preg_match('/^2189\d{8}$/', $digits)) {
            return true;
        }
        if (preg_match('/^20(10|11|12|15)\d{8}$/', $digits)) {
            return true;
        }

        return (bool) preg_match('/^[1-9]\d{9,14}$/', $digits);
    }

    /** @return list<string> */
    public function adminPhones(): array
    {
        $phones = [];
        foreach ((array) config('services.waha.admin_phones', []) as $phone) {
            $normalized = $this->normalizePhone((string) $phone);
            if ($normalized && $this->isValidInternational($normalized)) {
                $phones[$normalized] = $normalized;
            }
        }

        return array_values($phones);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    protected function prepareMessages(array $messages): array
    {
        $out = [];
        $seen = [];
        $notifyCustomers = $this->notifyCustomers();

        foreach ($messages as $message) {
            $recipientType = (string) ($message['recipient_type'] ?? 'customer');
            $isOtp = (bool) ($message['is_otp'] ?? $recipientType === 'otp');

            if (! $isOtp && $recipientType === 'customer' && ! $notifyCustomers) {
                continue;
            }

            $rawPhone = (string) ($message['phone'] ?? '');
            $phone = $this->normalizePhone($rawPhone);
            $text = WhatsAppMessage::rtlBlock(trim((string) ($message['text'] ?? '')));
            $image = $message['image'] ?? $message['image_url'] ?? null;

            if (! $phone) {
                WhatsAppLog::record([
                    'event' => $message['event'] ?? null,
                    'phone' => substr(preg_replace('/\D+/', '', $rawPhone) ?: 'unknown', 0, 32),
                    'recipient_type' => $recipientType,
                    'status' => 'skipped_invalid_phone',
                    'reference_type' => $message['reference_type'] ?? null,
                    'reference_id' => $message['reference_id'] ?? null,
                    'error' => 'normalize failed',
                ]);

                continue;
            }

            if ($text === '') {
                continue;
            }

            // Dedupe same phone in one batch (customer who is also admin, etc.)
            $imageKey = is_array($image)
                ? md5(($image['filename'] ?? '').substr((string) ($image['base64'] ?? ''), 0, 24))
                : (string) $image;
            $key = $phone.'|'.md5($text.'|'.$imageKey);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // Prefer first occurrence; if admin+customer same phone, keep whichever came first
            if (isset($seen['phone:'.$phone]) && ! $isOtp) {
                continue;
            }
            $seen['phone:'.$phone] = true;

            $out[] = [
                'phone' => $phone,
                'text' => $text,
                'image' => $image,
                'event' => $message['event'] ?? null,
                'recipient_type' => $isOtp ? 'otp' : $recipientType,
                'reference_type' => $message['reference_type'] ?? null,
                'reference_id' => $message['reference_id'] ?? null,
                'is_otp' => $isOtp,
            ];
        }

        return $out;
    }

    protected function sleepBetweenMessages(): void
    {
        $min = (int) config('services.waha.delay_min', 1);
        $max = (int) config('services.waha.delay_max', 3);
        if ($max < $min) {
            $max = $min;
        }
        if ($max <= 0) {
            return;
        }
        $seconds = $min === $max ? $min : random_int($min, $max);
        if ($seconds > 0) {
            usleep($seconds * 1_000_000);
        }
    }

    protected function dailyLimitReached(): bool
    {
        $limit = (int) config('services.waha.daily_limit', 50);
        if ($limit <= 0) {
            return false;
        }

        return WhatsAppLog::countTodayNonOtp() >= $limit;
    }

    protected function maybeAlertDailyLimit(): void
    {
        $key = 'whatsapp:daily_limit_alert:'.now()->toDateString();
        if (! Cache::add($key, 1, now()->endOfDay())) {
            return;
        }
        $emails = (array) config('services.waha.alert_emails', []);
        if ($emails === []) {
            Log::warning('[WhatsAppNotifier] daily limit reached — no WHATSAPP_ALERT_EMAILS configured');

            return;
        }
        try {
            Mail::to($emails)->send(new WhatsAppLimitReachedMail(
                (int) config('services.waha.daily_limit', 50),
                WhatsAppLog::countTodayNonOtp()
            ));
        } catch (\Throwable $e) {
            Log::warning('[WhatsAppNotifier] daily limit mail failed', ['error' => $e->getMessage()]);
        }
        Log::warning('[WhatsAppNotifier] daily limit reached', [
            'limit' => (int) config('services.waha.daily_limit', 50),
        ]);
    }

    /** @param  array<string, mixed>  $message */
    protected function shouldCoalesceSkip(array $message): bool
    {
        if (! empty($message['is_otp'])) {
            return false;
        }
        $seconds = (int) config('services.waha.coalesce_seconds', 60);
        if ($seconds <= 0) {
            return false;
        }
        $refType = $message['reference_type'] ?? null;
        $refId = $message['reference_id'] ?? null;
        if (! $refType || ! $refId) {
            return false;
        }
        $key = 'whatsapp:coalesce:'.$message['phone'].':'.$refType.':'.$refId;
        if (Cache::has($key)) {
            return true;
        }

        return false;
    }

    /** @param  array<string, mixed>  $message */
    protected function markCoalesce(array $message): void
    {
        if (! empty($message['is_otp'])) {
            return;
        }
        $seconds = (int) config('services.waha.coalesce_seconds', 60);
        if ($seconds <= 0) {
            return;
        }
        $refType = $message['reference_type'] ?? null;
        $refId = $message['reference_id'] ?? null;
        if (! $refType || ! $refId) {
            return;
        }
        Cache::put(
            'whatsapp:coalesce:'.$message['phone'].':'.$refType.':'.$refId,
            1,
            $seconds
        );
    }

    /** @param  array<string, mixed>  $message */
    protected function logAttempt(array $message, string $status, ?string $channel, ?string $error): void
    {
        WhatsAppLog::record([
            'event' => $message['event'] ?? null,
            'phone' => $message['phone'],
            'recipient_type' => $message['recipient_type'] ?? 'customer',
            'status' => $status,
            'channel' => $channel,
            'reference_type' => $message['reference_type'] ?? null,
            'reference_id' => $message['reference_id'] ?? null,
            'error' => $error,
        ]);
    }

    protected function sendText(string $baseUrl, string $session, string $phone, string $text, bool $isOtp = false): WahaResult
    {
        return $this->wahaRequest($baseUrl.'/api/sendText', [
            'session' => $session,
            'chatId' => $phone.'@c.us',
            'text' => $text,
        ], $phone, 'sendText', $isOtp);
    }

    /**
     * Try ONE image mode only: base64 preferred, else url. No serial storm.
     *
     * @param  string|array{url?:string,base64?:string,mimetype?:string,filename?:string}  $image
     */
    protected function sendImage(
        string $baseUrl,
        string $session,
        string $phone,
        string $caption,
        string|array $image,
        bool $isOtp = false,
    ): WahaResult {
        $endpoint = $baseUrl.'/api/sendImage';
        $chatId = $phone.'@c.us';

        if (is_array($image) && filled($image['base64'] ?? null)) {
            return $this->wahaRequest($endpoint, [
                'session' => $session,
                'chatId' => $chatId,
                'caption' => $caption,
                'file' => [
                    'mimetype' => (string) ($image['mimetype'] ?? 'image/jpeg'),
                    'filename' => (string) ($image['filename'] ?? 'mg-card.jpg'),
                    'data' => (string) $image['base64'],
                ],
            ], $phone, 'sendImage:base64', $isOtp);
        }

        $url = is_array($image) ? ($image['url'] ?? null) : $image;
        if (filled($url) && ! $this->urlLooksLocal((string) $url)) {
            return $this->wahaRequest($endpoint, [
                'session' => $session,
                'chatId' => $chatId,
                'caption' => $caption,
                'file' => [
                    'mimetype' => 'image/jpeg',
                    'filename' => 'mg-card.jpg',
                    'url' => (string) $url,
                ],
            ], $phone, 'sendImage:url', $isOtp);
        }

        return WahaResult::failure(WahaResult::REASON_MEDIA_ERROR, null, 'no usable image payload');
    }

    protected function urlLooksLocal(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
    }

    protected function hasImagePayload(mixed $image): bool
    {
        if (is_string($image) && filled($image)) {
            return true;
        }

        return is_array($image) && (filled($image['base64'] ?? null) || filled($image['url'] ?? null));
    }

    /**
     * Single choke point for all WAHA HTTP calls.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function wahaRequest(
        string $endpoint,
        array $payload,
        string $phone,
        string $action,
        bool $isOtp = false,
    ): WahaResult {
        if ($this->isBreakerOpen()) {
            return WahaResult::failure(WahaResult::REASON_BREAKER_OPEN);
        }

        try {
            $response = $this->wahaHttp($isOtp)->post($endpoint, $payload);

            if ($response->successful()) {
                return WahaResult::success($response->status());
            }

            $status = $response->status();
            $body = $response->body();
            $result = $this->classifyHttpFailure($status, $body);

            if ($result->shouldOpenBreaker()) {
                $this->openBreaker($result->reason.($result->detail ? ': '.$result->detail : ''));
            }

            $logBody = strlen($body) > 500 ? substr($body, 0, 500).'…' : $body;
            Log::error('[WhatsAppNotifier] WAHA '.$action.' failed', [
                'status' => $status,
                'phone' => $phone,
                'session' => $this->sessionName(),
                'reason' => $result->reason,
                'body' => $logBody,
            ]);

            return $result;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->openBreaker('timeout: '.$e->getMessage());
            Log::error('[WhatsAppNotifier] WAHA '.$action.' timeout', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return WahaResult::failure(WahaResult::REASON_TIMEOUT, null, $e->getMessage());
        } catch (\Throwable $e) {
            $this->openBreaker('exception: '.$e->getMessage());
            Log::error('[WhatsAppNotifier] WAHA '.$action.' exception', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return WahaResult::failure(WahaResult::REASON_TIMEOUT, null, $e->getMessage());
        }
    }

    protected function classifyHttpFailure(int $status, string $body): WahaResult
    {
        if ($status === 401 || $status === 403) {
            return WahaResult::failure(WahaResult::REASON_UNAUTHORIZED, $status, 'auth failed');
        }

        $lower = strtolower($body);
        if ($status === 422 || str_contains($lower, 'session status is not as expected')
            || str_contains($lower, '"status":"failed"')
            || str_contains($lower, '"status":"scan_qr_code"')
            || str_contains($lower, 'scan_qr_code')) {
            return WahaResult::failure(WahaResult::REASON_SESSION_DOWN, $status, 'session not WORKING');
        }

        if ($status >= 500) {
            return WahaResult::failure(WahaResult::REASON_HTTP_ERROR, $status, 'server error');
        }

        // Likely media/payload issue — do not open breaker for generic 4xx.
        return WahaResult::failure(WahaResult::REASON_MEDIA_ERROR, $status, 'http '.$status);
    }

    protected function wahaHttp(bool $isOtp = false): \Illuminate\Http\Client\PendingRequest
    {
        $connect = max(1, (int) config('services.waha.connect_timeout', 3));
        $timeout = $isOtp
            ? max(2, (int) config('services.waha.otp_timeout', 6))
            : max(2, (int) config('services.waha.timeout', 6));

        return Http::connectTimeout($connect)
            ->timeout($timeout)
            ->withHeaders([
                'X-Api-Key' => (string) config('services.waha.key'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
    }
}

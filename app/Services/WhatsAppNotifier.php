<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotification;
use App\Mail\WhatsAppLimitReachedMail;
use App\Models\WhatsAppLog;
use App\Support\WhatsAppMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WhatsAppNotifier
{
    /**
     * Immediate send (OTP). Never fans out to admins. Never delayed / never counts toward daily limit.
     *
     * @param  string|array{url?:string,base64?:string,mimetype?:string,filename?:string}|null  $image
     */
    public function send(string $phone, string $text, string|array|null $image = null): bool
    {
        try {
            return $this->deliver([[
                'phone' => $phone,
                'text' => $text,
                'image' => $image,
                'event' => 'otp',
                'recipient_type' => 'otp',
                'is_otp' => true,
            ]], skipDelay: true);
        } catch (\Throwable $e) {
            Log::error('[WhatsAppNotifier] send exception', ['error' => $e->getMessage()]);

            return false;
        }
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

            if (filter_var(config('services.waha.sync_alerts', false), FILTER_VALIDATE_BOOLEAN)
                || config('queue.default') === 'sync') {
                $this->deliver($prepared);

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
        try {
            $otpOnly = $messages !== [] && collect($messages)->every(
                fn ($m) => (bool) ($m['is_otp'] ?? (($m['recipient_type'] ?? '') === 'otp'))
            );

            // Marketing alerts respect WHATSAPP_NOTIFY_ENABLED.
            // Auth OTP must still attempt WAHA when URL/session are configured.
            if (! $this->enabled() && ! $otpOnly) {
                return false;
            }

            if (! $this->enabled() && $otpOnly) {
                Log::warning('[WhatsAppNotifier] WHATSAPP_NOTIFY_ENABLED is false — attempting OTP anyway');
            }

            $prepared = $this->prepareMessages($messages);
            if ($prepared === []) {
                return false;
            }

            $baseUrl = rtrim(trim((string) config('services.waha.url')), '/');
            if ($baseUrl === '') {
                Log::error('[WhatsAppNotifier] WAHA_URL is empty');

                return false;
            }

            $session = $this->sessionName();
            if ($session === '') {
                Log::error('[WhatsAppNotifier] WAHA_SESSION is empty');

                return false;
            }

            $allOk = true;
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
                    $allOk = false;

                    continue;
                }

                if (! $this->isValidInternational($message['phone'])) {
                    $this->logAttempt($message, 'skipped_invalid_phone', null, 'Invalid international phone');
                    $allOk = false;

                    continue;
                }

                if ($this->shouldCoalesceSkip($message)) {
                    continue;
                }

                $hasImage = $this->hasImagePayload($message['image'] ?? null);
                $ok = false;
                $channel = 'text';
                $status = 'failed';
                $error = null;

                if ($hasImage) {
                    $ok = $this->sendImage($baseUrl, $session, $message['phone'], $message['text'], $message['image']);
                    if ($ok) {
                        $channel = 'image';
                        $status = 'sent';
                    } else {
                        Log::warning('[WhatsAppNotifier] sendImage failed — fallback sendText', [
                            'phone' => $message['phone'],
                        ]);
                        $ok = $this->sendText($baseUrl, $session, $message['phone'], $message['text']);
                        if ($ok) {
                            $channel = 'text';
                            $status = 'fallback_text';
                        } else {
                            $error = 'image and text failed';
                        }
                    }
                } else {
                    $ok = $this->sendText($baseUrl, $session, $message['phone'], $message['text']);
                    $status = $ok ? 'sent' : 'failed';
                    if (! $ok) {
                        $error = 'sendText failed';
                    }
                }

                $this->logAttempt($message, $status, $channel, $error);
                if ($ok) {
                    $this->markCoalesce($message);
                } else {
                    $allOk = false;
                }
            }

            return $allOk;
        } catch (\Throwable $e) {
            Log::error('[WhatsAppNotifier] deliver exception', ['error' => $e->getMessage()]);

            return false;
        }
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

    protected function sendText(string $baseUrl, string $session, string $phone, string $text): bool
    {
        return $this->postJson($baseUrl.'/api/sendText', [
            'session' => $session,
            'chatId' => $phone.'@c.us',
            'text' => $text,
        ], $phone, 'sendText');
    }

    /**
     * @param  string|array{url?:string,base64?:string,mimetype?:string,filename?:string}  $image
     */
    protected function sendImage(string $baseUrl, string $session, string $phone, string $caption, string|array $image): bool
    {
        $endpoint = $baseUrl.'/api/sendImage';
        $chatId = $phone.'@c.us';
        $attempts = [];

        if (is_array($image) && filled($image['base64'] ?? null)) {
            $attempts[] = [
                'mode' => 'base64',
                'payload' => [
                    'session' => $session,
                    'chatId' => $chatId,
                    'caption' => $caption,
                    'file' => [
                        'mimetype' => (string) ($image['mimetype'] ?? 'image/jpeg'),
                        'filename' => (string) ($image['filename'] ?? 'mg-card.jpg'),
                        'data' => (string) $image['base64'],
                    ],
                ],
            ];
        }

        $url = is_array($image) ? ($image['url'] ?? null) : $image;
        if (filled($url) && ! $this->urlLooksLocal((string) $url)) {
            $attempts[] = [
                'mode' => 'url',
                'payload' => [
                    'session' => $session,
                    'chatId' => $chatId,
                    'caption' => $caption,
                    'file' => [
                        'mimetype' => 'image/jpeg',
                        'filename' => 'mg-card.jpg',
                        'url' => (string) $url,
                    ],
                ],
            ];
        }

        foreach ($attempts as $attempt) {
            if ($this->postJson($endpoint, $attempt['payload'], $phone, 'sendImage:'.$attempt['mode'])) {
                return true;
            }
        }

        return false;
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

    /** @param  array<string, mixed>  $payload */
    protected function postJson(string $endpoint, array $payload, string $phone, string $action): bool
    {
        try {
            $response = $this->wahaHttp()->post($endpoint, $payload);

            if ($response->successful()) {
                return true;
            }

            $body = $response->body();

            // Session FAILED/STOPPED — one restart then one retry (OTP recovery).
            if ($this->bodyLooksLikeDeadSession($body) && $this->restartWahaSessionOnce()) {
                sleep(2);
                $retry = $this->wahaHttp()->post($endpoint, $payload);
                if ($retry->successful()) {
                    Log::info('[WhatsAppNotifier] WAHA '.$action.' ok after session restart', [
                        'phone' => $phone,
                    ]);

                    return true;
                }
                $body = $retry->body();
            }

            if (strlen($body) > 500) {
                $body = substr($body, 0, 500).'…';
            }
            Log::error('[WhatsAppNotifier] WAHA '.$action.' failed', [
                'status' => $response->status(),
                'phone' => $phone,
                'session' => $this->sessionName(),
                'reason' => $body,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[WhatsAppNotifier] WAHA '.$action.' exception', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function wahaHttp(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(20)
            ->retry(2, 200, throw: false)
            ->withHeaders([
                'X-Api-Key' => (string) config('services.waha.key'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
    }

    protected function bodyLooksLikeDeadSession(string $body): bool
    {
        $lower = strtolower($body);

        return str_contains($lower, '"status":"failed"')
            || str_contains($lower, '"status":"stopped"')
            || str_contains($lower, 'session status is not as expected');
    }

    /**
     * At most one restart attempt per process window (Cache lock).
     */
    protected function restartWahaSessionOnce(): bool
    {
        $session = $this->sessionName();
        $base = rtrim(trim((string) config('services.waha.url')), '/');
        if ($session === '' || $base === '') {
            return false;
        }

        $lockKey = 'waha:session_restart:'.$session;
        if (! Cache::add($lockKey, 1, now()->addMinutes(2))) {
            return false;
        }

        try {
            Log::warning('[WhatsAppNotifier] restarting FAILED WAHA session', ['session' => $session]);
            $response = $this->wahaHttp()->timeout(45)
                ->post("{$base}/api/sessions/{$session}/restart");

            return $response->successful() || $response->status() < 500;
        } catch (\Throwable $e) {
            Log::error('[WhatsAppNotifier] session restart failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}

<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

trait SendsMarsolSmsOtp
{
    /**
     * Normalize a phone for Marsol APIs (digits only, Libya → 2189…).
     *
     * @throws InvalidArgumentException
     */
    protected function normalizeMarsolPhone(string $raw): string
    {
        $cleaned = preg_replace('/[\s\-\(\)]+/', '', $raw) ?? '';
        $cleaned = preg_replace('/^\+/', '', $cleaned) ?? $cleaned;

        if (str_starts_with($cleaned, '00')) {
            $cleaned = substr($cleaned, 2);
        }

        $digits = preg_replace('/\D+/', '', $cleaned) ?? '';

        if ($digits === '') {
            Log::error('[Marsol SMS] empty phone after normalize', ['raw' => $raw]);
            throw new InvalidArgumentException('No valid phone number for Marsol');
        }

        // Libyan local: 09xxxxxxxx → 2189xxxxxxxx
        if (preg_match('/^0(9[1-5]\d{7})$/', $digits, $m)) {
            $digits = '218'.$m[1];
        }
        // Libyan without trunk 0: 9xxxxxxxx → 2189xxxxxxxx
        elseif (preg_match('/^(9[1-5]\d{7})$/', $digits)) {
            $digits = '218'.$digits;
        }

        // Valid Libya mobile
        if (preg_match('/^218(9[1-5])\d{7}$/', $digits)) {
            return $digits;
        }

        // Other international (non-218): 10–15 digits, pass through
        if (! str_starts_with($digits, '218') && preg_match('/^\d{10,15}$/', $digits)) {
            return $digits;
        }

        Log::error('[Marsol SMS] invalid phone for Marsol', [
            'raw' => $raw,
            'normalized' => $digits,
        ]);

        throw new InvalidArgumentException('No valid phone number for Marsol');
    }

    /** @deprecated Prefer normalizeMarsolPhone(); kept for internal BC. */
    protected function normalizeForMarsol(string $phone): ?string
    {
        try {
            return $this->normalizeMarsolPhone($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    protected function isLibyaPhone(string $phone): bool
    {
        try {
            $normalized = $this->normalizeMarsolPhone($phone);

            return (bool) preg_match('/^218(9[1-5])\d{7}$/', $normalized);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Shared Marsol HTTP client.
     *
     * @throws RuntimeException when the API token is missing
     */
    protected function marsolClient(): PendingRequest
    {
        $token = $this->marsolConfigValue('token');

        if ($token === '') {
            throw new RuntimeException('Marsol credentials missing — set MARSOL_API_TOKEN then run php artisan config:cache');
        }

        return Http::baseUrl(rtrim((string) config('services.marsol.base_url', 'https://api.marsol.ly'), '/'))
            ->withHeaders([
                'x-auth-token' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(15);
    }

    /** Strip whitespace / wrapping quotes that often sneak into .env values. */
    protected function marsolConfigValue(string $key): string
    {
        $value = trim((string) config('services.marsol.'.$key));

        return trim($value, " \t\n\r\0\x0B\"'");
    }

    /**
     * Resolve a valid Sender ID UUID from config or Marsol GET /public/senderIds.
     *
     * @see https://docs.marsol.ly/senderids
     *
     * @throws RuntimeException
     */
    protected function marsolSenderId(): string
    {
        static $resolved = null;

        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        $configured = $this->marsolConfigValue('sender_id');
        $senders = $this->fetchMarsolSenderIds();

        if ($senders === []) {
            throw new RuntimeException(
                'Marsol has no available senderIds for this token — check the Marsol dashboard / GET /public/senderIds'
            );
        }

        // Prefer configured UUID when it exists and is available.
        if ($configured !== '') {
            foreach ($senders as $sender) {
                $id = (string) ($sender['id'] ?? '');
                if ($id === $configured && ($sender['available'] ?? true)) {
                    $resolved = $id;

                    return $resolved;
                }
            }

            Log::warning('[Marsol SMS] configured MARSOL_SENDER_ID is invalid for this account — falling back to default', [
                'configured' => $configured,
                'available_ids' => array_values(array_filter(array_map(
                    fn ($s) => $s['id'] ?? null,
                    $senders
                ))),
            ]);
        }

        // Prefer account default, then any available SMS-capable sender.
        $fallback = null;
        foreach ($senders as $sender) {
            if (! ($sender['available'] ?? true)) {
                continue;
            }

            $id = (string) ($sender['id'] ?? '');
            if ($id === '') {
                continue;
            }

            if (($sender['isDefault'] ?? false) === true) {
                $resolved = $id;
                Log::info('[Marsol SMS] using default senderId', [
                    'senderId' => $id,
                    'name' => $sender['name'] ?? null,
                ]);

                return $resolved;
            }

            $fallback ??= $id;
        }

        if ($fallback === null) {
            throw new RuntimeException('Marsol senderIds found but none are available');
        }

        $resolved = $fallback;
        Log::info('[Marsol SMS] using fallback senderId', ['senderId' => $resolved]);

        return $resolved;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchMarsolSenderIds(): array
    {
        try {
            $res = $this->marsolClient()->get('/public/senderIds');

            if (! $res->successful()) {
                Log::error('[Marsol SMS] senderIds list failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);

                return [];
            }

            $json = $res->json();

            return is_array($json) ? array_values($json) : [];
        } catch (\Throwable $e) {
            Log::error('[Marsol SMS] senderIds list exception', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** Snap expiration to one of Marsol's allowed values: 120, 300, 600. */
    protected function marsolOtpExpiration(int $expiration): int
    {
        $allowed = [120, 300, 600];

        if (in_array($expiration, $allowed, true)) {
            return $expiration;
        }

        usort($allowed, fn (int $a, int $b) => abs($a - $expiration) <=> abs($b - $expiration));

        return $allowed[0];
    }

    protected function marsolAppName(): string
    {
        $name = $this->marsolConfigValue('app_name');

        if ($name === '' || strtoupper($name) === 'NONE' || strtoupper($name) === 'NULL') {
            $name = trim((string) config('app.name', 'MG Plastic'));
        }

        if ($name === '' || strtoupper($name) === 'NONE' || strtoupper($name) === 'NULL') {
            $name = 'MG Plastic';
        }

        return $name;
    }

    /**
     * Branded OTP SMS body (Arabic / English).
     * Marsol's built-in OTP template uses the project name (often "NONE") — we avoid that.
     */
    protected function marsolOtpMessage(string $otp, int $ttlMinutes = 5, ?string $language = null): string
    {
        $app = $this->marsolAppName();
        $lang = strtoupper($language ?: (app()->getLocale() === 'ar' ? 'AR' : 'EN'));

        if ($lang === 'AR') {
            return "رمز التأكيد الخاص بك لـ {$app}\nCode:{$otp}\nصالح لمدة {$ttlMinutes} دقائق\nلا تشاركه مع أي أحد";
        }

        return "Your confirmation code for {$app}\nCode:{$otp}\nValid for {$ttlMinutes} minutes\nDo not share it with anyone";
    }

    /**
     * Issue an OTP: branded SMS by default (shows real app name).
     * Set MARSOL_USE_OTP_API=true to use Marsol's OTP initiate template instead.
     *
     * @return array{
     *   mode: 'sms'|'otp_api',
     *   otp:?string,
     *   requestId:?string,
     *   resendToken:?string,
     *   expiration:int
     * }|null
     */
    protected function issueMarsolPhoneOtp(
        string $phone,
        int $length = 6,
        int $expiration = 300,
        string $clientOs = 'WEB',
        string $language = 'EN',
        string $operation = 'CODE'
    ): ?array {
        $expiration = $this->marsolOtpExpiration($expiration);
        $ttlMinutes = max(1, (int) round($expiration / 60));

        if (config('services.marsol.use_otp_api')) {
            $resp = $this->initiateMarsolOtp($phone, $length, $expiration, $clientOs, $language, $operation);

            if (! $resp || empty($resp['requestId'])) {
                return null;
            }

            return [
                'mode' => 'otp_api',
                'otp' => null,
                'requestId' => $resp['requestId'],
                'resendToken' => $resp['resendToken'] ?? null,
                'expiration' => (int) ($resp['expiration'] ?? $expiration),
            ];
        }

        $max = (10 ** $length) - 1;
        $min = 10 ** ($length - 1);
        $otp = (string) random_int($min, $max);

        if (! $this->sendMarsolSmsOtp($phone, $otp, $ttlMinutes, $language)) {
            return null;
        }

        return [
            'mode' => 'sms',
            'otp' => $otp,
            'requestId' => null,
            'resendToken' => null,
            'expiration' => $expiration,
        ];
    }

    /**
     * Persist an issueMarsolPhoneOtp() result onto the user row.
     *
     * @param  array{mode:string,otp:?string,requestId:?string,resendToken:?string,expiration:int}  $issued
     */
    protected function applyMarsolOtpToUser(User $user, array $issued): void
    {
        $exp = max(60, min((int) $issued['expiration'], 86400));

        if (($issued['mode'] ?? '') === 'otp_api') {
            $user->update([
                'marsol_otp_request_id' => $issued['requestId'],
                'marsol_otp_resend_token' => $issued['resendToken'] ?? null,
                'marsol_otp_expires_at' => now()->addSeconds($exp),
                'otp_code' => null,
                'otp_expires_at' => null,
                'otp_last_sent_at' => now(),
                'otp_attempts' => 0,
            ]);

            return;
        }

        $user->update([
            'otp_code' => $issued['otp'],
            'otp_expires_at' => now()->addSeconds($exp),
            'otp_last_sent_at' => now(),
            'otp_attempts' => 0,
            'marsol_otp_request_id' => null,
            'marsol_otp_resend_token' => null,
            'marsol_otp_expires_at' => null,
        ]);
    }

    /* ============================================================
     * INITIATE OTP – Marsol OTP API
     * ============================================================
     */
    protected function initiateMarsolOtp(
        string $phone,
        int $length = 6,
        int $expiration = 300,
        string $clientOs = 'WEB',
        string $language = 'EN',
        string $operation = 'CODE'
    ): ?array {
        try {
            $normalized = $this->normalizeMarsolPhone($phone);
        } catch (InvalidArgumentException $e) {
            Log::error('[Marsol OTP] Invalid phone format', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $expiration = $this->marsolOtpExpiration($expiration);

        $payload = [
            'phoneNumber' => $normalized,
            'length' => $length,
            'expiration' => $expiration,
            'clientOs' => $clientOs,
            'language' => $language,
            'operation' => $operation,
            'senderId' => $this->marsolSenderId(),
        ];

        try {
            $res = $this->marsolClient()->post('/public/otp/initiate', $payload);

            if (! $res->successful()) {
                $context = [
                    'status' => $res->status(),
                    'body' => $res->body(),
                    'phone' => $normalized,
                    'senderId' => $payload['senderId'] ?? null,
                ];

                if ($res->status() === 401) {
                    $context['hint'] = 'Check MARSOL_API_TOKEN is active (x-auth-token) then php artisan config:cache';
                }

                Log::error('[Marsol OTP] initiate failed', $context);

                return null;
            }

            $json = $res->json();

            Log::info('[Marsol OTP] initiate ok', [
                'phone' => $normalized,
                'requestId' => $json['requestId'] ?? null,
                'expiration' => $json['expiration'] ?? $expiration,
            ]);

            return is_array($json) ? $json : null;
        } catch (RuntimeException $e) {
            Log::critical('[Marsol OTP] initiate exception', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::critical('[Marsol OTP] initiate exception', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* ============================================================
     * VERIFY OTP – Marsol OTP API
     * ============================================================
     */
    protected function verifyMarsolOtp(string $requestId, string $code, string $operation = 'CODE'): ?array
    {
        $payload = [
            'code' => $code,
            'requestId' => $requestId,
            'operation' => $operation,
        ];

        try {
            $res = $this->marsolClient()->post('/public/otp/verify', $payload);

            if (! $res->successful()) {
                Log::error('[Marsol OTP] verify failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                    'requestId' => $requestId,
                ]);

                return null;
            }

            return $res->json();
        } catch (\Throwable $e) {
            Log::critical('[Marsol OTP] verify exception', [
                'requestId' => $requestId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* ============================================================
     * RESEND OTP – Marsol OTP API
     * ============================================================
     */
    protected function resendMarsolOtp(string $requestId, string $resendToken, string $operation = 'CODE'): ?array
    {
        $payload = [
            'requestId' => $requestId,
            'resendToken' => $resendToken,
            'operation' => $operation,
        ];

        try {
            $res = $this->marsolClient()->post('/public/otp/resend', $payload);

            if (! $res->successful()) {
                Log::error('[Marsol OTP] resend failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                    'requestId' => $requestId,
                ]);

                return null;
            }

            return $res->json();
        } catch (\Throwable $e) {
            Log::critical('[Marsol OTP] resend exception', [
                'requestId' => $requestId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* ============================================================
     * SMS API (non-Libya / local OTP fallback)
     * ============================================================
     */
    protected function sendMarsolSmsOtp(string $phone, string $otp, int $ttlMinutes = 5, ?string $language = null): bool
    {
        try {
            $normalized = $this->normalizeMarsolPhone($phone);
        } catch (InvalidArgumentException $e) {
            Log::error('[Marsol SMS] Failed to normalize phone', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $payload = [
            'phoneNumbers' => [$normalized],
            'message' => $this->marsolOtpMessage($otp, $ttlMinutes, $language),
            'senderId' => $this->marsolSenderId(),
        ];

        try {
            $res = $this->marsolClient()->post('/public/sms/send', $payload);

            if (! $res->successful()) {
                Log::error('[Marsol SMS] failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                    'phone' => $normalized,
                    'app' => $this->marsolAppName(),
                ]);

                return false;
            }

            $json = $res->json();
            $rejected = is_array($json) ? ($json['rejected'] ?? []) : [];

            if (is_array($rejected) && $rejected !== []) {
                Log::error('[Marsol SMS] rejected', [
                    'phone' => $normalized,
                    'rejected' => $rejected,
                    'accepted' => $json['accepted'] ?? null,
                    'requestId' => $json['requestId'] ?? null,
                ]);

                return false;
            }

            Log::info('[Marsol SMS] sent', [
                'phone' => $normalized,
                'app' => $this->marsolAppName(),
                'requestId' => is_array($json) ? ($json['requestId'] ?? null) : null,
                'accepted' => is_array($json) ? ($json['accepted'] ?? null) : null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::critical('[Marsol SMS] exception', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

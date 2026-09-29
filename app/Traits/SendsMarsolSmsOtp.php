<?php

namespace App\Traits;

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

        // Nearest allowed value
        usort($allowed, fn (int $a, int $b) => abs($a - $expiration) <=> abs($b - $expiration));

        return $allowed[0];
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
    protected function sendMarsolSmsOtp(string $phone, string $otp, int $ttlMinutes = 5): bool
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
            'message' => "Your verification code is {$otp}. It expires in {$ttlMinutes} minutes.",
            'senderId' => $this->marsolSenderId(),
        ];

        try {
            $res = $this->marsolClient()->post('/public/sms/send', $payload);

            if (! $res->successful()) {
                Log::error('[Marsol SMS] failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                    'phone' => $normalized,
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

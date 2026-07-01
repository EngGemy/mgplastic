<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait SendsMarsolSmsOtp
{
    /* ============================================================
     * Normalize phone number for Libya (218 + localPhone)
     * ============================================================
     */
    protected function normalizeForMarsol(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (!$digits) return null;

        // Remove leading 00
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Local Libyan 0XXXXXXXXX → 218XXXXXXXXX
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '218' . substr($digits, 1);
        }

        return $digits;
    }

    /* ============================================================
     * Detect Libya numbers (starts with 2189…)
     * ============================================================
     */
    protected function isLibyaPhone(string $phone): bool
    {
        $normalized = $this->normalizeForMarsol($phone);
        return $normalized && str_starts_with($normalized, '218');
    }

    /* ============================================================
     * Base Http Client
     * ============================================================
     */
    protected function marsolClient()
    {
        return Http::baseUrl(
            rtrim(config('services.marsol.base_url', 'https://api.marsol.ly'), '/')
        )
            ->withHeaders([
                'x-auth-token' => config('services.marsol.token'),
                'Accept'       => 'application/json',
            ]);
    }

    /* ============================================================
     * 🇱🇾 INITIATE OTP – Marsol OTP API
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

        $normalized = $this->normalizeForMarsol($phone);

        if (!$normalized) {
            Log::error('[Marsol OTP] Invalid phone format', ['phone' => $phone]);
            return null;
        }

        $payload = [
            "phoneNumber" => $normalized,   // IMPORTANT ✔
            "length"      => $length,
            "expiration"  => $expiration,
            "clientOs"    => $clientOs,
            "language"    => $language,
            "operation"   => $operation,
        ];

        try {
            $res = $this->marsolClient()->post('/public/otp/initiate', $payload);

            if (!$res->successful()) {
                Log::error('[Marsol OTP] initiate failed', [
                    'status'  => $res->status(),
                    'body'    => $res->body(),
                    'payload' => $payload,
                ]);
                return null;
            }

            return $res->json();

        } catch (\Throwable $e) {
            Log::critical('[Marsol OTP] initiate exception', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /* ============================================================
     * 🇱🇾 VERIFY OTP – Marsol OTP API
     * ============================================================
     */
    protected function verifyMarsolOtp(string $requestId, string $code, string $operation = 'CODE'): ?array
    {
        $payload = [
            "code"      => $code,
            "requestId" => $requestId,
            "operation" => $operation,
        ];

        try {
            $res = $this->marsolClient()->post('/public/otp/verify', $payload);

            if (!$res->successful()) {
                Log::error('[Marsol OTP] verify failed', [
                    'status'  => $res->status(),
                    'body'    => $res->body(),
                ]);
                return null;
            }

            return $res->json();

        } catch (\Throwable $e) {
            Log::critical('[Marsol OTP] verify exception', [
                'requestId' => $requestId,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /* ============================================================
     * 🇱🇾 RESEND OTP – Marsol OTP API
     * ============================================================
     */
    protected function resendMarsolOtp(string $requestId, string $resendToken, string $operation = 'CODE'): ?array
    {
        $payload = [
            "requestId"   => $requestId,
            "resendToken" => $resendToken,
            "operation"   => $operation,
        ];

        try {
            $res = $this->marsolClient()->post('/public/otp/resend', $payload);

            if (!$res->successful()) {
                Log::error('[Marsol OTP] resend failed', [
                    'status'  => $res->status(),
                    'body'    => $res->body(),
                ]);
                return null;
            }

            return $res->json();

        } catch (\Throwable $e) {
            Log::critical('[Marsol OTP] resend exception', [
                'requestId' => $requestId,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /* ============================================================
     * 🌍 SMS API (non-Libya fallback)
     * ============================================================
     */
    protected function sendMarsolSmsOtp(string $phone, string $otp, int $ttlMinutes = 5): bool
    {
        $normalized = $this->normalizeForMarsol($phone);

        if (!$normalized) {
            Log::error('[Marsol SMS] Failed to normalize phone', ['phone' => $phone]);
            return false;
        }

        $payload = [
            "phoneNumbers" => [$normalized],
            "message"      => "Your verification code is {$otp}. It expires in {$ttlMinutes} minutes.",
        ];

        try {
            $res = $this->marsolClient()->post('/public/sms/send', $payload);

            if (!$res->successful()) {
                Log::error('[Marsol SMS] failed', [
                    'status'  => $res->status(),
                    'body'    => $res->body(),
                ]);
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            Log::critical('[Marsol SMS] exception', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

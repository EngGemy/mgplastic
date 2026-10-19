<?php

namespace App\Services;

use App\Models\User;
use App\Support\ArabicWait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PhoneOtpService
{
    public function __construct(
        protected WhatsAppNotifier $whatsapp,
    ) {}

    /**
     * Issue a 6-digit OTP, store hashed, send via WhatsApp sync (never to admins).
     *
     * @return array{otp: string, expires_at: Carbon}
     */
    public function issue(User $user, string $reason = 'login'): array
    {
        $limiterKey = 'otp:'.$reason.':'.($user->phone ?: $user->id);

        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            throw ValidationException::withMessages([
                'phone' => [ArabicWait::message($seconds, 'طلب رمز تحقق جديد')],
            ]);
        }

        RateLimiter::hit($limiterKey, 3600);

        $otp = (string) random_int(100000, 999999);
        $expiresAt = Carbon::now()->addMinutes(5);

        $user->forceFill([
            'otp_code' => Hash::make($otp),
            'otp_expires_at' => $expiresAt,
            'otp_last_sent_at' => now(),
            'otp_attempts' => 0,
            'marsol_otp_request_id' => null,
            'marsol_otp_resend_token' => null,
            'marsol_otp_expires_at' => null,
        ])->save();

        $text = "MG للصناعات البلاستيكية\nرمز التحقق الخاص بك: {$otp}\nصالح لمدة 5 دقائق. لا تشاركه مع أي شخص.";

        // Sync only — never fan-out to admins
        $this->whatsapp->send((string) $user->phone, $text);

        return [
            'otp' => $otp,
            'expires_at' => $expiresAt,
        ];
    }

    public function matches(User $user, string $otp): bool
    {
        if (! $user->otp_code || ! $user->otp_expires_at || now()->gt($user->otp_expires_at)) {
            return false;
        }

        $stored = (string) $user->otp_code;

        // Hashed (bcrypt/argon)
        if (str_starts_with($stored, '$2y$')
            || str_starts_with($stored, '$2a$')
            || str_starts_with($stored, '$2b$')
            || str_starts_with($stored, '$argon')) {
            return Hash::check($otp, $stored);
        }

        // Legacy plain OTP (pre-migration)
        return hash_equals($stored, $otp);
    }
}

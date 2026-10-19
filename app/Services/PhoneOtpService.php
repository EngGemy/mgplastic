<?php

namespace App\Services;

use App\Models\User;
use App\Support\ArabicWait;
use App\Traits\SendsMarsolSmsOtp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PhoneOtpService
{
    use SendsMarsolSmsOtp;

    public function __construct(
        protected WhatsAppNotifier $whatsapp,
    ) {}

    /**
     * Issue a 6-digit OTP and deliver via configured channels (whatsapp and/or sms).
     * Success = at least one enabled channel delivered. Never notifies admins.
     *
     * Constraint (Marsol): /public/sms/send is blocked (unverified account).
     * Marsol OTP API (/public/otp/initiate) generates its own code and cannot
     * accept our local OTP — so WhatsApp carries the local code, SMS may carry
     * a different Marsol-generated code. matches() accepts either.
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

        $channels = $this->enabledChannels();
        if ($channels === []) {
            throw ValidationException::withMessages([
                'phone' => ['تعذّر إرسال رمز التحقق — حاول لاحقاً'],
            ]);
        }

        $otp = (string) random_int(100000, 999999);
        $expiresAt = Carbon::now()->addMinutes(5);
        $text = "╔══════════════════════╗\n"
            ."║  🏭 *MG للصناعات البلاستيكية*\n"
            ."╚══════════════════════╝\n\n"
            ."🔐 *رمز التحقق الآمن*\n"
            ."────────────────────\n"
            ."رمزك: *{$otp}*\n"
            ."⏱ صالح لمدة *5 دقائق فقط*\n"
            ."⚠️ لا تشاركه مع أي شخص.\n\n"
            ."💙 _فريق MG يحمي حسابك_";

        $whatsappOk = false;
        $smsOk = false;
        $marsolRequestId = null;
        $marsolResendToken = null;
        $marsolExpiration = 300;
        $otpCard = app(WhatsAppBrandCard::class)->urlFor('otp', 'رمز التحقق');

        if (in_array('whatsapp', $channels, true)) {
            try {
                $whatsappOk = $this->whatsapp->send((string) $user->phone, $text, $otpCard);
            } catch (\Throwable $e) {
                Log::error('[OTP] WhatsApp channel failed', [
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);
                $whatsappOk = false;
            }
        }

        if (in_array('sms', $channels, true)) {
            try {
                $issued = $this->issueMarsolPhoneOtp((string) $user->phone, 6, 300, 'WEB', 'AR', 'CODE');
                if ($issued && ! empty($issued['requestId'])) {
                    $smsOk = true;
                    $marsolRequestId = $issued['requestId'];
                    $marsolResendToken = $issued['resendToken'] ?? null;
                    $marsolExpiration = (int) ($issued['expiration'] ?? 300);
                }
            } catch (\Throwable $e) {
                Log::error('[OTP] Marsol SMS channel failed', [
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);
                $smsOk = false;
            }
        }

        $wantWa = in_array('whatsapp', $channels, true);
        $wantSms = in_array('sms', $channels, true);
        $success = ($wantWa && $whatsappOk) || ($wantSms && $smsOk);

        if (! $success) {
            throw ValidationException::withMessages([
                'phone' => ['تعذّر إرسال رمز التحقق — حاول لاحقاً'],
            ]);
        }

        RateLimiter::hit($limiterKey, 3600);

        $user->forceFill([
            'otp_code' => Hash::make($otp),
            'otp_expires_at' => $expiresAt,
            'otp_last_sent_at' => now(),
            'otp_attempts' => 0,
            'marsol_otp_request_id' => $marsolRequestId,
            'marsol_otp_resend_token' => $marsolResendToken,
            'marsol_otp_expires_at' => $marsolRequestId
                ? now()->addSeconds(max(60, min($marsolExpiration, 86400)))
                : null,
        ])->save();

        return [
            'otp' => $otp,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Accept local WhatsApp OTP (hashed) OR Marsol OTP API verify (different code).
     */
    public function matches(User $user, string $otp): bool
    {
        if ($user->otp_code && $user->otp_expires_at && now()->lte($user->otp_expires_at)) {
            $stored = (string) $user->otp_code;

            if (str_starts_with($stored, '$2y$')
                || str_starts_with($stored, '$2a$')
                || str_starts_with($stored, '$2b$')
                || str_starts_with($stored, '$argon')) {
                if (Hash::check($otp, $stored)) {
                    return true;
                }
            } elseif (hash_equals($stored, $otp)) {
                return true;
            }
        }

        if ($user->marsol_otp_request_id) {
            if ($user->marsol_otp_expires_at && now()->gt($user->marsol_otp_expires_at)) {
                return false;
            }

            try {
                $resp = $this->verifyMarsolOtp(
                    (string) $user->marsol_otp_request_id,
                    $otp,
                    'CODE'
                );

                if ($resp && ($resp['status'] ?? null) === 'SUCCESS') {
                    return true;
                }
            } catch (\Throwable $e) {
                Log::error('[OTP] Marsol verify failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function enabledChannels(): array
    {
        $raw = (string) config('services.otp.channels', 'whatsapp,sms');
        $allowed = ['whatsapp', 'sms'];

        $channels = [];
        foreach (explode(',', $raw) as $part) {
            $name = strtolower(trim($part));
            if (in_array($name, $allowed, true)) {
                $channels[$name] = $name;
            }
        }

        return array_values($channels);
    }
}

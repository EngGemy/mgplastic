<?php

namespace App\Services;

use App\Models\User;
use App\Support\ArabicWait;
use App\Support\WahaResult;
use App\Support\WhatsAppMessage;
use App\Traits\SendsMarsolSmsOtp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PhoneOtpService
{
    use SendsMarsolSmsOtp;

    public const MAX_VERIFY_ATTEMPTS = 5;

    public function __construct(
        protected WhatsAppNotifier $whatsapp,
    ) {}

    public function isLockedOut(User $user): bool
    {
        return (int) $user->otp_attempts >= self::MAX_VERIFY_ATTEMPTS;
    }

    public function registerFailedVerify(User $user): void
    {
        $user->increment('otp_attempts');
    }

    public function lockoutResponse(): array
    {
        return [
            'status' => false,
            'message' => 'تم تجاوز عدد محاولات رمز التحقق — اطلب رمزاً جديداً',
            'code' => 'otp_locked',
        ];
    }

    /**
     * Issue a 6-digit OTP and deliver via ONE chosen channel (whatsapp|sms).
     * Caller must pass an explicit channel — never blast both without user choice.
     *
     * Constraint (Marsol): SMS uses Marsol OTP API (own code). WhatsApp uses local hashed OTP.
     * matches() accepts either.
     *
     * Honor the user's channel choice exactly:
     * - whatsapp → WAHA only
     * - sms → Marsol only
     * Never auto-switch channels.
     *
     * @param  'whatsapp'|'sms'  $channel
     * @return array{channel: string, expires_at: Carbon, otp_expires_at: string, delivery?: string}
     */
    public function issue(User $user, string $reason = 'login', ?string $channel = null): array
    {
        $limiterKey = 'otp:'.$reason.':'.($user->phone ?: $user->id);

        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            throw ValidationException::withMessages([
                'phone' => [ArabicWait::message($seconds, 'طلب رمز تحقق جديد')],
            ]);
        }

        $channel = $this->normalizeChannel($channel);
        if ($channel === null) {
            throw ValidationException::withMessages([
                'channel' => ['اختر طريقة الإرسال: واتساب أو رسالة SMS'],
            ]);
        }

        if (! in_array($channel, $this->enabledChannels(), true)) {
            throw ValidationException::withMessages([
                'channel' => ['طريقة الإرسال غير متاحة حاليًا'],
            ]);
        }

        $otp = (string) random_int(100000, 999999);
        $expiresAt = Carbon::now()->addMinutes(5);
        $text = WhatsAppMessage::otp($otp);

        $marsolRequestId = null;
        $marsolResendToken = null;
        $marsolExpiration = 300;
        $delivery = 'sent';

        if ($channel === 'whatsapp') {
            $started = microtime(true);
            $result = null;

            try {
                $result = $this->whatsapp->sendDetailed((string) $user->phone, $text, null);
            } catch (\Throwable $e) {
                $this->logOtpOutcome($reason, $user, 'whatsapp_exception', null, $started, [
                    'error' => $e->getMessage(),
                ]);
                throw ValidationException::withMessages([
                    'channel' => [$this->whatsappFailureMessage($user)],
                ]);
            }

            $elapsedMs = (int) round((microtime(true) - $started) * 1000);

            if ($result->ok) {
                $this->logOtpOutcome($reason, $user, 'whatsapp_ok', $result, $started);
            } elseif ($result->deliveryUnknown) {
                // Read timeout: message may already be on the phone — keep OTP valid.
                $delivery = 'pending';
                Log::warning('[OTP] whatsapp delivery_unknown — treating as success-pending', [
                    'branch' => 'PhoneOtpService::issue.whatsapp_delivery_unknown',
                    'reason' => $reason,
                    'channel' => 'whatsapp',
                    'phone' => $this->maskPhone($user->phone),
                    'waha_reason' => $result->reason,
                    'http_status' => $result->status,
                    'elapsed_ms' => $elapsedMs,
                    'breaker_state' => $this->whatsapp->isBreakerOpen() ? 'open' : 'closed',
                ]);
            } else {
                $this->logOtpOutcome($reason, $user, 'whatsapp_failed', $result, $started);
                throw ValidationException::withMessages([
                    'channel' => [$this->whatsappFailureMessage($user)],
                ]);
            }
        }

        if ($channel === 'sms') {
            $smsOk = false;
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
                    'branch' => 'PhoneOtpService::issue.sms_exception',
                    'reason' => $reason,
                    'phone' => $this->maskPhone($user->phone),
                    'error' => $e->getMessage(),
                ]);
                $smsOk = false;
            }

            if (! $smsOk) {
                throw ValidationException::withMessages([
                    'channel' => ['تعذّر إرسال رمز التحقق عبر SMS — جرّب واتساب'],
                ]);
            }
        }

        RateLimiter::hit($limiterKey, 3600);

        $user->forceFill([
            // Keep local hash only when WhatsApp carries our code; SMS uses Marsol verify.
            'otp_code' => $channel === 'whatsapp' ? Hash::make($otp) : null,
            'otp_expires_at' => $expiresAt,
            'otp_last_sent_at' => now(),
            'otp_attempts' => 0,
            'marsol_otp_request_id' => $marsolRequestId,
            'marsol_otp_resend_token' => $marsolResendToken,
            'marsol_otp_expires_at' => $marsolRequestId
                ? now()->addSeconds(max(60, min($marsolExpiration, 86400)))
                : null,
        ])->save();

        $result = [
            'channel' => $channel,
            'expires_at' => $expiresAt,
            'otp_expires_at' => $expiresAt->toIso8601String(),
        ];

        if ($channel === 'whatsapp' && $delivery === 'pending') {
            $result['delivery'] = 'pending';
        }

        // Tests only — never expose OTP in production API responses.
        if (app()->runningUnitTests() && $channel === 'whatsapp') {
            $result['otp'] = $otp;
        }

        return $result;
    }

    public function smsAvailableFor(?string $phone): bool
    {
        return $this->isLibyaPhone((string) $phone);
    }

    public function whatsappFailureMessage(User $user): string
    {
        if ($this->smsAvailableFor($user->phone)) {
            return 'تعذّر إرسال رمز التحقق عبر واتساب — جرّب SMS أو حاول لاحقًا';
        }

        return 'تعذّر إرسال رمز التحقق عبر واتساب — حاول لاحقًا';
    }

    /** @return 'whatsapp'|'sms'|null */
    public function normalizeChannel(?string $channel): ?string
    {
        $name = strtolower(trim((string) $channel));

        return in_array($name, ['whatsapp', 'sms'], true) ? $name : null;
    }

    /**
     * Channels shown on the mobile OTP picker (always WhatsApp + SMS).
     * Delivery still respects enabledChannels() inside issue().
     *
     * @return list<array{key: string, label: string, icon: string}>
     */
    public function channelOptions(): array
    {
        // Product contract: UI always offers both channels.
        // Do not filter by OTP_CHANNELS here — a production env set to
        // "whatsapp" alone was hiding SMS from mobile/settings.
        return [
            ['key' => 'whatsapp', 'label' => 'واتساب', 'icon' => 'whatsapp'],
            ['key' => 'sms', 'label' => 'رسالة SMS', 'icon' => 'sms'],
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
     * Channels allowed for actual OTP delivery.
     * Always matches channelOptions() (WhatsApp + SMS) so the mobile picker
     * never offers a channel the API then rejects. Missing credentials fail
     * at send time with a clear message instead.
     *
     * @return list<string>
     */
    public function enabledChannels(): array
    {
        return ['whatsapp', 'sms'];
    }

    protected function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
        if (strlen($digits) < 4) {
            return '****';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }

    protected function logOtpOutcome(
        string $reason,
        User $user,
        string $branch,
        ?WahaResult $result,
        float $startedAt,
        array $extra = [],
    ): void {
        Log::log(
            str_contains($branch, 'fail') || str_contains($branch, 'exception') ? 'error' : 'info',
            '[OTP] '.$branch,
            array_merge([
                'branch' => 'PhoneOtpService::issue.'.$branch,
                'reason' => $reason,
                'channel' => 'whatsapp',
                'phone' => $this->maskPhone($user->phone),
                'waha_reason' => $result?->reason,
                'http_status' => $result?->status,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'breaker_state' => $this->whatsapp->isBreakerOpen() ? 'open' : 'closed',
            ], $extra)
        );
    }
}

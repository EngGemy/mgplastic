<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpTicketService;
use App\Services\PhoneOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordResetByPhoneController extends Controller
{
    public const TICKET_PURPOSE = 'forgot';

    /**
     * Step 1 — phone only: return channel picker (no OTP sent).
     * Step 2 — phone/ticket + channel: send OTP on the chosen channel.
     */
    public function sendOtp(Request $request)
    {
        $request->validate([
            'phone' => ['nullable', 'string', 'required_without:otp_ticket'],
            'otp_ticket' => ['nullable', 'string', 'required_without:phone'],
            'channel' => ['nullable', 'string', 'in:whatsapp,sms'],
        ]);

        $otp = app(PhoneOtpService::class);
        $channels = $otp->channelOptions();
        $channel = $otp->normalizeChannel($request->input('channel'));

        $user = $this->resolveUser($request);

        // No channel yet → show WhatsApp | SMS picker (same as login OTP gate).
        if ($channel === null) {
            if (! $user) {
                return response()->json([
                    'status' => true,
                    'message' => 'اختر طريقة إرسال رمز التحقق',
                    'code' => 'channel_required',
                    'requires_channel' => true,
                    'otp_sent' => false,
                    'otp_ticket' => null,
                    'otp_channels' => $channels,
                    'otp_ticket_ttl_minutes' => OtpTicketService::TTL_MINUTES,
                ]);
            }

            $ticket = app(OtpTicketService::class)->issue($user, self::TICKET_PURPOSE);

            return response()->json([
                'status' => true,
                'message' => 'اختر طريقة إرسال رمز التحقق',
                'code' => 'channel_required',
                'requires_channel' => true,
                'otp_sent' => false,
                'otp_ticket' => $ticket,
                'otp_channels' => $channels,
                'otp_ticket_ttl_minutes' => OtpTicketService::TTL_MINUTES,
                'phone_hint' => $this->phoneHint($user->phone),
            ]);
        }

        // Channel chosen — avoid account enumeration on unknown phones.
        if (! $user) {
            return response()->json([
                'status' => true,
                'message' => 'إن وُجد الحساب سيتم إرسال رمز التحقق',
                'code' => 'otp_sent',
                'otp_sent' => true,
                'channel' => $channel,
                'otp_channels' => $channels,
            ]);
        }

        if ($request->filled('otp_ticket')) {
            $ticketUser = app(OtpTicketService::class)->userFromTicket(
                (string) $request->input('otp_ticket'),
                self::TICKET_PURPOSE,
            );
            if (! $ticketUser || (int) $ticketUser->id !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'انتهت صلاحية جلسة التحقق — أعد المحاولة',
                    'code' => 'otp_ticket_expired',
                ], 401);
            }
        }

        if ($user->otp_last_sent_at && abs(now()->diffInSeconds($user->otp_last_sent_at)) < 60) {
            return response()->json([
                'status' => false,
                'message' => 'انتظر دقيقة قبل طلب رمز تحقق جديد',
                'code' => 'otp_cooldown',
            ], 429);
        }

        try {
            $meta = $otp->issue($user, reason: 'forgot', channel: $channel);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'تعذّر إرسال رمز التحقق',
                'code' => 'otp_send_failed',
                'otp_channels' => $channels,
            ], 422);
        }

        $delivered = $meta['channel'];
        $fallbackFrom = $meta['fallback_from'] ?? null;
        $message = match (true) {
            $fallbackFrom === 'whatsapp' && $delivered === 'sms' => 'واتساب غير متاح حاليًا — تم إرسال الرمز عبر رسالة SMS',
            $delivered === 'whatsapp' => 'تم إرسال رمز التحقق عبر واتساب',
            default => 'تم إرسال رمز التحقق عبر رسالة SMS',
        };

        return response()->json([
            'status' => true,
            'message' => $message,
            'code' => 'otp_sent',
            'otp_sent' => true,
            'channel' => $delivered,
            'fallback_from' => $fallbackFrom,
            'otp_expires_at' => $meta['otp_expires_at'],
            'otp_channels' => $channels,
            'phone_hint' => $this->phoneHint($user->phone),
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'otp' => 'required|string',
        ]);

        $user = User::findByPhone((string) $request->phone);
        if (! $user) {
            return response()->json(['status' => false, 'message' => 'المستخدم غير موجود'], 404);
        }

        if (! app(PhoneOtpService::class)->matches($user, (string) $request->otp)) {
            if ($user->otp_code) {
                $user->increment('otp_attempts');
            }

            return response()->json(['status' => false, 'message' => 'رمز التحقق غير صحيح أو منتهي'], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم التحقق من الرمز بنجاح',
            'code' => 'otp_verified',
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'otp' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::findByPhone((string) $request->phone);

        if (! $user) {
            return response()->json(['status' => false, 'message' => 'المستخدم غير موجود'], 404);
        }

        if (! app(PhoneOtpService::class)->matches($user, (string) $request->otp)) {
            if ($user->otp_code) {
                $user->increment('otp_attempts');
            }

            return response()->json(['status' => false, 'message' => 'رمز التحقق غير صحيح أو منتهي'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->otp_code = null;
        $user->otp_expires_at = null;
        $user->marsol_otp_request_id = null;
        $user->marsol_otp_resend_token = null;
        $user->marsol_otp_expires_at = null;
        $user->otp_attempts = 0;
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح',
            'code' => 'password_reset',
        ]);
    }

    protected function resolveUser(Request $request): ?User
    {
        if ($request->filled('otp_ticket')) {
            $fromTicket = app(OtpTicketService::class)->userFromTicket(
                (string) $request->input('otp_ticket'),
                self::TICKET_PURPOSE,
            );
            if ($fromTicket) {
                return $fromTicket;
            }
        }

        if ($request->filled('phone')) {
            return User::findByPhone((string) $request->phone);
        }

        return null;
    }

    protected function phoneHint(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
        if (strlen($digits) < 4) {
            return null;
        }

        return str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}

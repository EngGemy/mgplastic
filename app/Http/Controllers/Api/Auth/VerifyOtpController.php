<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\User;
use App\Services\OtpTicketService;
use App\Services\PhoneOtpService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VerifyOtpController extends Controller
{
    /**
     * POST /auth/send-otp
     * Body: otp_ticket + channel (whatsapp|sms)
     * Sends OTP on the channel the user chose — required before verify.
     */
    public function send(Request $request)
    {
        $request->validate([
            'otp_ticket' => ['required', 'string'],
            'channel' => ['required', 'string', 'in:whatsapp,sms'],
            'phone' => ['nullable', 'string'],
        ]);

        $user = app(OtpTicketService::class)->userFromTicket((string) $request->input('otp_ticket'));

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'انتهت صلاحية جلسة التحقق — سجّل الدخول من جديد',
                'code' => 'otp_ticket_expired',
            ], 401);
        }

        if ($request->filled('phone')) {
            $byPhone = User::findByPhone((string) $request->phone)
                ?? User::where('phone', $request->phone)->first();
            if (! $byPhone || (int) $byPhone->id !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'رقم الهاتف غير مطابق لجلسة التحقق',
                ], 422);
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
            $meta = app(PhoneOtpService::class)->issue(
                $user,
                reason: 'send',
                channel: (string) $request->input('channel'),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'تعذّر إرسال رمز التحقق',
                'code' => 'otp_send_failed',
            ], 422);
        }

        $channel = $meta['channel'];

        return response()->json([
            'status' => true,
            'message' => $channel === 'whatsapp'
                ? 'تم إرسال رمز التحقق عبر واتساب'
                : 'تم إرسال رمز التحقق عبر رسالة SMS',
            'code' => 'otp_sent',
            'requires_otp' => true,
            'otp_sent' => true,
            'channel' => $channel,
            'otp_expires_at' => $meta['otp_expires_at'],
            'phone_hint' => $this->phoneHint($user->phone),
        ]);
    }

    public function verify(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'otp' => 'required|string',
            'otp_ticket' => ['nullable', 'string'],
        ]);

        $user = User::findByPhone((string) $request->phone)
            ?? User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['status' => false, 'message' => 'المستخدم غير موجود'], 404);
        }

        // Prefer ticket binding when present (prevents verifying another phone mid-flow).
        if ($request->filled('otp_ticket')) {
            $ticketUser = app(OtpTicketService::class)->userFromTicket((string) $request->input('otp_ticket'));
            if (! $ticketUser || (int) $ticketUser->id !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'جلسة التحقق غير صالحة — سجّل الدخول من جديد',
                    'code' => 'otp_ticket_expired',
                ], 401);
            }
        }

        if (! $user->is_active) {
            return response()->json([
                'status' => false,
                'message' => 'حسابك موقوف — تواصل مع الدعم',
                'code' => 'account_revoked',
            ], 403);
        }

        if (! app(PhoneOtpService::class)->matches($user, (string) $request->otp)) {
            if ($user->otp_code || $user->marsol_otp_request_id) {
                $user->increment('otp_attempts');
            }

            return response()->json(['status' => false, 'message' => 'رمز التحقق غير صحيح أو منتهي'], 422);
        }

        $user->update([
            'is_phone_verified' => true,
            'otp_code' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
            'marsol_otp_request_id' => null,
            'marsol_otp_resend_token' => null,
            'marsol_otp_expires_at' => null,
        ]);

        // Drop any stale tokens from before verification.
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        $hasBiometric = BiometricDevice::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        $payload = [
            'status' => true,
            'message' => 'تم توثيق الهاتف بنجاح',
            'code' => 'otp_verified',
            'token' => $token,
            'user' => $user->fresh(),
            'prompt_biometric' => ! $hasBiometric,
            'requires_otp' => false,
        ];

        if ($user->isNetworkStore()) {
            $status = $user->networkStoreStatus();
            $payload['store_status'] = [
                'code' => $status['code'],
                'is_approved' => (bool) $user->is_approved,
                'is_active' => (bool) $user->is_active,
                'is_public' => $status['is_public'],
                'notice' => $status['message'],
            ];
            if ($status['message']) {
                $payload['message'] = $status['message'];
            }
        }

        return response()->json($payload);
    }

    public function resend(Request $request)
    {
        $request->validate([
            'otp_ticket' => ['required', 'string'],
            'channel' => ['required', 'string', 'in:whatsapp,sms'],
            'phone' => ['nullable', 'string'],
        ]);

        // Same path as send — keep alias for older app builds.
        return $this->send($request);
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

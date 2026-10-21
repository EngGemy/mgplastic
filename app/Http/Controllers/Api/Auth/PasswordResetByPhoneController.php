<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PhoneOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordResetByPhoneController extends Controller
{
    public function sendOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'channel' => ['nullable', 'string', 'in:whatsapp,sms'],
        ]);

        $user = User::findByPhone((string) $request->phone);

        // Avoid enumeration — same success copy either way
        if (! $user) {
            return response()->json([
                'status' => true,
                'message' => 'إن وُجد الحساب سيتم إرسال رمز التحقق',
            ]);
        }

        if ($user->otp_last_sent_at && abs(now()->diffInSeconds($user->otp_last_sent_at)) < 60) {
            return response()->json([
                'status' => false,
                'message' => 'انتظر دقيقة قبل طلب رمز تحقق جديد',
            ], 429);
        }

        $otp = app(PhoneOtpService::class);
        $channel = $otp->normalizeChannel($request->input('channel'))
            ?? ($otp->enabledChannels()[0] ?? null);

        try {
            $otp->issue($user, reason: 'forgot', channel: $channel);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'تعذّر إرسال رمز التحقق',
            ], 429);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم إرسال رمز التحقق',
            'channel' => $channel,
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
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PhoneOtpService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VerifyOtpController extends Controller
{
    public function verify(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'otp' => 'required|string',
        ]);

        $user = User::findByPhone((string) $request->phone)
            ?? User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['status' => false, 'message' => 'المستخدم غير موجود'], 404);
        }

        if (! app(PhoneOtpService::class)->matches($user, (string) $request->otp)) {
            if ($user->otp_code) {
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

        $token = $user->createToken('auth_token')->plainTextToken;

        $payload = [
            'status' => true,
            'message' => 'تم توثيق الهاتف بنجاح',
            'token' => $token,
            'user' => $user,
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
        $request->validate(['phone' => 'required|string']);

        $user = User::findByPhone((string) $request->phone)
            ?? User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['status' => false, 'message' => 'المستخدم غير موجود'], 404);
        }

        if ($user->otp_last_sent_at && abs(now()->diffInSeconds($user->otp_last_sent_at)) < 60) {
            return response()->json([
                'status' => false,
                'message' => 'انتظر دقيقة قبل طلب رمز تحقق جديد',
            ], 429);
        }

        try {
            app(PhoneOtpService::class)->issue($user, reason: 'resend');
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'تعذّر إرسال رمز التحقق',
            ], 429);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم إعادة إرسال رمز التحقق',
        ]);
    }
}

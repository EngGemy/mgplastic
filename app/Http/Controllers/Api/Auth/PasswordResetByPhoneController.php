<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\SendsMarsolSmsOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasswordResetByPhoneController extends Controller
{
    use SendsMarsolSmsOtp;

    public function sendOtp(Request $request)
    {
        $request->validate(['phone' => 'required|string']);

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

        $issued = $this->issueMarsolPhoneOtp($user->phone, 6, 300, 'WEB', 'AR', 'CODE');

        if (! $issued) {
            return response()->json([
                'status' => false,
                'message' => 'تعذّر إرسال رمز التحقق — حاول لاحقاً',
            ], 500);
        }

        $this->applyMarsolOtpToUser($user, $issued);

        return response()->json([
            'status' => true,
            'message' => 'تم إرسال رمز التحقق',
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

        $isLibya = $this->isLibyaPhone($user->phone);

        if ($isLibya && $user->marsol_otp_request_id) {
            $resp = $this->verifyMarsolOtp(
                $user->marsol_otp_request_id,
                $request->otp,
                'CODE'
            );

            if (! $resp || ($resp['status'] ?? null) !== 'SUCCESS') {
                return response()->json(['status' => false, 'message' => 'رمز التحقق غير صحيح أو منتهي'], 422);
            }
        } else {
            if (! $user->otp_code || ! $user->otp_expires_at || now()->gt($user->otp_expires_at)) {
                return response()->json(['status' => false, 'message' => 'انتهت صلاحية الرمز — اطلب رمزاً جديداً'], 422);
            }

            if ($user->otp_code !== $request->otp) {
                $user->increment('otp_attempts');

                return response()->json(['status' => false, 'message' => 'رمز التحقق غير صحيح'], 422);
            }
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

        $isLibya = $this->isLibyaPhone($user->phone);

        if ($isLibya && $user->marsol_otp_request_id) {
            $resp = $this->verifyMarsolOtp(
                $user->marsol_otp_request_id,
                $request->otp,
                'CODE'
            );

            if (! $resp || ($resp['status'] ?? null) !== 'SUCCESS') {
                return response()->json(['status' => false, 'message' => 'رمز التحقق غير صحيح أو منتهي'], 422);
            }
        } else {
            if (! $user->otp_code || ! $user->otp_expires_at || now()->gt($user->otp_expires_at)) {
                return response()->json(['status' => false, 'message' => 'انتهت صلاحية الرمز'], 422);
            }

            if ($user->otp_code !== $request->otp) {
                $user->increment('otp_attempts');

                return response()->json(['status' => false, 'message' => 'رمز التحقق غير صحيح'], 422);
            }
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

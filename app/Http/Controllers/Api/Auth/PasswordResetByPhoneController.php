<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Traits\SendsMarsolSmsOtp;

class PasswordResetByPhoneController extends Controller
{
    use SendsMarsolSmsOtp;

    /**
     * Step 1: Send OTP to phone (FORGOT PASSWORD)
     */
    public function sendOtp(Request $request)
    {
        $request->validate(['phone' => 'required|string']);

        $user = User::where('phone', $request->phone)->first();

        // Avoid enumeration
        if (! $user) {
            return response()->json([
                'status'  => true,
                'message' => 'If the phone exists, an OTP has been sent.',
            ]);
        }

        if ($user->otp_last_sent_at && now()->diffInSeconds($user->otp_last_sent_at) < 60) {
            return response()->json(['status'=>false,'message'=>'Please wait before requesting another OTP'], 429);
        }

        $lang = app()->getLocale() === 'ar' ? 'AR' : 'EN';
        $issued = $this->issueMarsolPhoneOtp($user->phone, 6, 300, 'WEB', $lang, 'CODE');

        if (! $issued) {
            return response()->json(['status' => false, 'message' => 'Failed to send OTP'], 500);
        }

        $this->applyMarsolOtpToUser($user, $issued);

        return response()->json([
            'status' => true,
            'message' => 'OTP sent.',
        ]);
    }

    /**
     * Step 2: Verify OTP (optional)
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'otp'   => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->first();
        if (! $user) {
            return response()->json(['status'=>false,'message'=>'User not found'],404);
        }

        $isLibya = $this->isLibyaPhone($user->phone);

        // =====================================================
        // 🇱🇾 LIBYA → VERIFY USING MARSOL API
        // =====================================================
        if ($isLibya && $user->marsol_otp_request_id) {

            $resp = $this->verifyMarsolOtp(
                $user->marsol_otp_request_id,
                $request->otp,
                'CODE'
            );

            if (! $resp || ($resp['status'] ?? null) !== 'SUCCESS') {
                return response()->json(['status'=>false,'message'=>'Invalid or expired OTP'],422);
            }
        }
        // =====================================================
        // 🌍 NON-LIBYA → LOCAL VERIFY
        // =====================================================
        else {
            if (! $user->otp_code || ! $user->otp_expires_at || now()->gt($user->otp_expires_at)) {
                return response()->json(['status'=>false,'message'=>'OTP expired. Please resend.'],422);
            }

            if ($user->otp_code !== $request->otp) {
                $user->increment('otp_attempts');
                return response()->json(['status'=>false,'message'=>'Invalid OTP'],422);
            }
        }

        return response()->json([
            'status'=>true,
            'message'=>'OTP verified successfully.'
        ]);
    }

    /**
     * Step 3: Reset password
     */
    public function reset(Request $request)
    {
        $request->validate([
            'phone'                 => 'required|string',
            'otp'                   => 'required|string',
            'password'              => 'required|string|min:6|confirmed',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['status'=>false,'message'=>'User not found'],404);
        }

        $isLibya = $this->isLibyaPhone($user->phone);

        // =====================================================
        // 🇱🇾 LIBYA → VERIFY VIA MARSOL BEFORE RESET
        // =====================================================
        if ($isLibya && $user->marsol_otp_request_id) {

            $resp = $this->verifyMarsolOtp(
                $user->marsol_otp_request_id,
                $request->otp,
                'CODE'
            );

            if (! $resp || ($resp['status'] ?? null) !== 'SUCCESS') {
                return response()->json(['status'=>false,'message'=>'Invalid or expired OTP'],422);
            }
        }
        // =====================================================
        // 🌍 NON-LIBYA → LOCAL OTP CHECK
        // =====================================================
        else {

            if (! $user->otp_code || ! $user->otp_expires_at || now()->gt($user->otp_expires_at)) {
                return response()->json(['status'=>false,'message'=>'OTP expired.'],422);
            }

            if ($user->otp_code !== $request->otp) {
                $user->increment('otp_attempts');
                return response()->json(['status'=>false,'message'=>'Invalid OTP'],422);
            }
        }

        // =====================================================
        // RESET PASSWORD
        // =====================================================
        $user->password = Hash::make($request->password);
        $user->otp_code = null;
        $user->otp_expires_at = null;
        $user->marsol_otp_request_id = null;
        $user->marsol_otp_resend_token = null;
        $user->marsol_otp_expires_at = null;
        $user->otp_attempts = 0;
        $user->save();

        return response()->json([
            'status'=>true,
            'message'=>'Password has been reset successfully.',
        ]);
    }
}

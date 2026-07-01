<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Traits\SendsMarsolSmsOtp;

class VerifyOtpController extends Controller
{
    use SendsMarsolSmsOtp;

    /* ============================================================
     * VERIFY OTP
     * ============================================================
     */
    public function verify(Request $request)
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

        // ============================================================
        // 🇱🇾 LIBYA → MARSOL VERIFY
        // ============================================================
        if ($isLibya && $user->marsol_otp_request_id) {

            $resp = $this->verifyMarsolOtp(
                $user->marsol_otp_request_id,
                $request->otp,
                'CODE'
            );

            if (! $resp || ($resp['status'] ?? null) !== 'SUCCESS') {
                return response()->json(['status'=>false,'message'=>'Invalid or expired OTP'],422);
            }

        } else {

            // ============================================================
            // 🌍 NON-LIBYA → LOCAL OTP CHECK
            // ============================================================
            if (!$user->otp_code || !$user->otp_expires_at || now()->gt($user->otp_expires_at)) {
                return response()->json(['status'=>false,'message'=>'OTP expired. Please resend.'],422);
            }

            if ($user->otp_code !== $request->otp) {
                $user->increment('otp_attempts');
                return response()->json(['status'=>false,'message'=>'Invalid OTP'],422);
            }
        }

        // ============================================================
        // SUCCESS → CLEAR OTP DATA
        // ============================================================
        $user->update([
            'is_phone_verified'       => true,
            'otp_code'                => null,
            'otp_expires_at'          => null,
            'otp_attempts'            => 0,
            'marsol_otp_request_id'   => null,
            'marsol_otp_resend_token' => null,
            'marsol_otp_expires_at'   => null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Phone verified successfully.',
            'token' => $token,
            'user'  => $user,
        ]);
    }

    /* ============================================================
     * RESEND OTP
     * ============================================================
     */
    public function resend(Request $request)
    {
        $request->validate(['phone' => 'required|string']);

        $user = User::where('phone', $request->phone)->first();
        if (! $user) {
            return response()->json(['status'=>false,'message'=>'User not found'],404);
        }

        // Prevent spam
        if ($user->otp_last_sent_at && now()->diffInSeconds($user->otp_last_sent_at) < 60) {
            return response()->json(['status'=>false,'message'=>'Please wait before requesting another OTP'],429);
        }

        $isLibya = $this->isLibyaPhone($user->phone);

        // ============================================================
        // 🇱🇾 LIBYA → MARSOL RESEND
        // ============================================================
        if ($isLibya && $user->marsol_otp_request_id && $user->marsol_otp_resend_token) {

            $resp = $this->resendMarsolOtp(
                $user->marsol_otp_request_id,
                $user->marsol_otp_resend_token,
                'CODE'
            );

            if (! $resp || empty($resp['requestId'])) {
                return response()->json(['status'=>false,'message'=>'Failed to resend OTP'],500);
            }

            $exp = $resp['expiration'] ?? 300;
            $exp = max(60, min($exp, 86400));

            $user->update([
                'marsol_otp_request_id'   => $resp['requestId'],
                'marsol_otp_resend_token' => $resp['resendToken'] ?? $user->marsol_otp_resend_token,
                'marsol_otp_expires_at'   => now()->addSeconds($exp),
                'otp_last_sent_at'        => now(),
                'otp_attempts'            => 0,
            ]);

        } else {

            // ============================================================
            // 🌍 NON-LIBYA → LOCAL OTP
            // ============================================================
            $otp = random_int(100000,999999);

            $user->update([
                'otp_code'         => $otp,
                'otp_expires_at'   => now()->addMinutes(5),
                'otp_last_sent_at' => now(),
                'otp_attempts'     => 0,
            ]);

            $sent = $this->sendMarsolSmsOtp($user->phone,$otp,5);

            if (! $sent) {
                return response()->json(['status'=>false,'message'=>'Failed to send SMS'],500);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'OTP resent successfully.',
        ]);
    }
}

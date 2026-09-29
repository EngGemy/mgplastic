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

        // Marsol OTP API verify (never local SMS codes for Libya / Marsol flow).
        if ($user->marsol_otp_request_id) {

            $resp = $this->verifyMarsolOtp(
                $user->marsol_otp_request_id,
                $request->otp,
                'CODE'
            );

            if (! $resp || ($resp['status'] ?? null) !== 'SUCCESS') {
                return response()->json(['status'=>false,'message'=>'Invalid or expired OTP'],422);
            }

        } else {

            // Legacy local OTP (only if no Marsol request was started).
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

        $payload = [
            'status' => true,
            'message' => 'Phone verified successfully.',
            'token' => $token,
            'user'  => $user,
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

        $lang = app()->getLocale() === 'ar' ? 'AR' : 'EN';

        // Marsol OTP API only (company SMS account is not verified).
        if ($user->marsol_otp_request_id && $user->marsol_otp_resend_token) {
            $resp = $this->resendMarsolOtp(
                $user->marsol_otp_request_id,
                $user->marsol_otp_resend_token,
                'CODE'
            );

            if (! $resp || empty($resp['requestId'])) {
                // Token expired — start a fresh OTP initiate.
                $issued = $this->issueMarsolPhoneOtp($user->phone, 6, 300, 'WEB', $lang, 'CODE');
                if (! $issued) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Failed to resend OTP',
                        'error' => $this->lastMarsolError,
                    ], 500);
                }
                $this->applyMarsolOtpToUser($user, $issued);
            } else {
                $exp = max(60, min((int) ($resp['expiration'] ?? 300), 86400));
                $user->update([
                    'marsol_otp_request_id' => $resp['requestId'],
                    'marsol_otp_resend_token' => $resp['resendToken'] ?? $user->marsol_otp_resend_token,
                    'marsol_otp_expires_at' => now()->addSeconds($exp),
                    'otp_last_sent_at' => now(),
                    'otp_attempts' => 0,
                    'otp_code' => null,
                    'otp_expires_at' => null,
                ]);
            }
        } else {
            $issued = $this->issueMarsolPhoneOtp($user->phone, 6, 300, 'WEB', $lang, 'CODE');

            if (! $issued) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to send OTP',
                    'error' => $this->lastMarsolError,
                ], 500);
            }

            $this->applyMarsolOtpToUser($user, $issued);
        }

        return response()->json([
            'status' => true,
            'message' => 'OTP resent successfully.',
        ]);
    }
}

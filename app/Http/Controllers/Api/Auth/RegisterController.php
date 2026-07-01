<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Traits\SendsMarsolSmsOtp;

class RegisterController extends Controller
{
    use SendsMarsolSmsOtp;

    public function register(RegisterRequest $request)
    {
        $phone = $request->phone;
        $isLibya = $this->isLibyaPhone($phone);

        // Local OTP only for non-Libya
        $localOtp = $isLibya ? null : random_int(100000, 999999);

        $user = User::create([
            'name'             => $request->name,
            'email'            => $request->email,
            'phone'            => $phone,
            'password'         => Hash::make($request->password),
            'role'             => 'customer',
            'otp_code'         => $localOtp,
            'otp_expires_at'   => $isLibya ? null : now()->addMinutes(5),
        ]);

        // =====================================================
        // 🇱🇾 LIBYA → SEND OTP THROUGH MARSOL
        // =====================================================
        if ($isLibya) {

            $otpResp = $this->initiateMarsolOtp(
                $phone,
                6,        // OTP length
                300,      // seconds
                'WEB',
                app()->getLocale() === 'ar' ? 'AR' : 'EN',
                'CODE'
            );

            if (! $otpResp || empty($otpResp['requestId'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'User created, but OTP sending failed.',
                ], 500);
            }

            // expiration fix
            $exp = $otpResp['expiration'] ?? 300;
            $exp = max(60, min($exp, 86400)); // 1m → 24h

            $user->update([
                'marsol_otp_request_id'   => $otpResp['requestId'],
                'marsol_otp_resend_token' => $otpResp['resendToken'] ?? null,
                'marsol_otp_expires_at'   => now()->addSeconds($exp),
                'otp_last_sent_at'        => now(),
            ]);
        }
        // =====================================================
        // 🌍 NON-LIBYA → SEND NORMAL SMS
        // =====================================================
        else {
            $sent = $this->sendMarsolSmsOtp($phone, $localOtp, 5);
            if (! $sent) {
                return response()->json([
                    'status' => false,
                    'message' => 'User created, but OTP SMS failed.',
                ], 500);
            }

            $user->update(['otp_last_sent_at' => now()]);
        }

        return response()->json([
            'status' => true,
            'message' => 'User registered. OTP sent.',
            'data' => [
                'user'  => $user,
                'token' => $user->createToken('auth_token')->plainTextToken,
            ]
        ]);
    }
}

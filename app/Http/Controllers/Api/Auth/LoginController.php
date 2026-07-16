<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Jobs\SendOtpSms;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'phone'    => ['required','string'],
            'password' => ['required','string'],
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (! $user || ! Hash::check($request->password, (string) $user->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // If phone not verified -> SAME RESPONSE SHAPE + OTP
        if (! $this->isPhoneVerified($user)) {
            $otpMeta = $this->issueOtp($user, reason: 'login');

            $user->is_phone_verified = false;

            return response()->json([
                'status'  => true,
                'message' => 'Phone not verified. OTP sent.',
                'token'   => null,
                'user'    => $user->makeHidden(['otp_code','otp_expires_at']),
                'otp'            => $otpMeta['otp'], // DEV ONLY
                'otp_expires_at' => $otpMeta['expires_at']->toIso8601String(),
            ], 200);
        }

        // Network stores may log in while pending approval to manage their profile.
        $isNetworkStore = $user->isNetworkStore();

        if (! $isNetworkStore && method_exists($user, 'isApprovedAndActive') && ! $user->isApprovedAndActive()) {
            return response()->json([
                'status'  => false,
                'message' => $user->is_approved
                    ? 'Your account is deactivated. Please contact support.'
                    : 'Awaiting admin approval.',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $message = 'Logged in successfully';
        if ($isNetworkStore && ! $user->is_approved) {
            $message = 'تم تسجيل الدخول. متجرك لم يُفعَّل بعد — بانتظار موافقة الإدارة.';
        } elseif ($isNetworkStore && ! $user->is_active) {
            $message = 'تم تسجيل الدخول. نشاط المتجر موقوف حالياً.';
        }

        return response()->json([
            'status'  => true,
            'message' => $message,
            'token'   => $token,
            'user'    => $user->makeHidden(['otp_code','otp_expires_at']),
        ], 200);
    }

    protected function isPhoneVerified(User $user): bool
    {
        if (isset($user->is_phone_verified)) {
            return (bool) $user->is_phone_verified;
        }
        if (isset($user->phone_verified_at)) {
            return ! is_null($user->phone_verified_at);
        }
        return false;
    }

    protected function issueOtp(User $user, string $reason = 'login'): array
    {
        $limiterKey = "otp:$reason:".$user->phone;

        // Allow up to 5 OTPs per hour per phone for this reason
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            throw ValidationException::withMessages([
                'phone' => ["Too many OTP requests. Try again in {$seconds} seconds."],
            ]);
        }

        RateLimiter::hit($limiterKey, 3600);

        $otp       = random_int(100000, 999999);
        $expiresAt = Carbon::now()->addMinutes(5);

        $user->forceFill([
            'otp_code'          => $otp,
            'otp_expires_at'    => $expiresAt,
            'is_phone_verified' => false,
        ])->save();

        if (class_exists(SendOtpSms::class)) {
            SendOtpSms::dispatch($user->phone, $otp);
        }

        return [
            'otp'        => $otp,
            'expires_at' => $expiresAt,
        ];
    }
}

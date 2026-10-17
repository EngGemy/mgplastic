<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendOtpSms;
use App\Models\User;
use App\Models\WalletAccount;
use App\Support\ArabicWait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'login' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = (string) (
            $request->input('login')
            ?? $request->input('phone')
            ?? $request->input('email')
            ?? ''
        );

        if (trim($identifier) === '') {
            return response()->json([
                'status' => false,
                'message' => 'أدخل رقم الهاتف أو البريد الإلكتروني',
            ], 422);
        }

        $user = User::findByLoginIdentifier($identifier);

        if (! $user || ! Hash::check($request->password, (string) $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'رقم الهاتف أو كلمة المرور غير صحيحة',
            ], 401);
        }

        // If phone not verified -> SAME RESPONSE SHAPE + OTP
        if (! $this->isPhoneVerified($user)) {
            $otpMeta = $this->issueOtp($user, reason: 'login');

            $user->is_phone_verified = false;

            return response()->json([
                'status' => true,
                'message' => 'الهاتف غير موثّق — تم إرسال رمز التحقق',
                'token' => null,
                'user' => $this->userPayload($user),
                'otp' => $otpMeta['otp'], // DEV ONLY
                'otp_expires_at' => $otpMeta['expires_at']->toIso8601String(),
            ], 200);
        }

        // Network stores may log in while pending approval to manage their profile.
        $isNetworkStore = $user->isNetworkStore();

        if (! $isNetworkStore && method_exists($user, 'isApprovedAndActive') && ! $user->isApprovedAndActive()) {
            return response()->json([
                'status' => false,
                'message' => $user->is_approved
                    ? 'حسابك موقوف — تواصل مع الدعم'
                    : 'حسابك بانتظار موافقة الإدارة',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $message = 'تم تسجيل الدخول بنجاح';
        if ($isNetworkStore && ! $user->is_approved) {
            $message = 'تم تسجيل الدخول. متجرك لم يُفعَّل بعد — بانتظار موافقة الإدارة.';
        } elseif ($isNetworkStore && ! $user->is_active) {
            $message = 'تم تسجيل الدخول. نشاط المتجر موقوف حالياً.';
        }

        return response()->json([
            'status'  => true,
            'message' => $message,
            'token'   => $token,
            'user'    => $this->userPayload($user),
        ], 200);
    }

    protected function userPayload(User $user): array
    {
        $payload = $user->makeHidden(['otp_code', 'otp_expires_at'])->toArray();

        if ($user->role === User::ROLE_PLUMBER || $user->role === 'plumber') {
            $payload['balance_points'] = (int) (WalletAccount::query()
                ->where('owner_id', $user->id)
                ->where('currency', 'LYD')
                ->value('balance_points') ?? 0);
        }

        return $payload;
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
                'phone' => [ArabicWait::message($seconds, 'طلب رمز تحقق جديد')],
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

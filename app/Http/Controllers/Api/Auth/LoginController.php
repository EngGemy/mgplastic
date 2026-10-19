<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\PhoneOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
            $otpMeta = app(PhoneOtpService::class)->issue($user, reason: 'login');

            $user->is_phone_verified = false;

            return response()->json([
                'status' => true,
                'message' => 'الهاتف غير موثّق — تم إرسال رمز التحقق',
                'token' => null,
                'user' => $this->userPayload($user),
                'otp' => $otpMeta['otp'], // DEV ONLY — never log / forward to admins
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
            'status' => true,
            'message' => $message,
            'token' => $token,
            'user' => $this->userPayload($user),
        ], 200);
    }

    protected function userPayload(User $user): array
    {
        $payload = $user->makeHidden(['otp_code', 'otp_expires_at'])->toArray();
        $payload['is_central_warehouse'] = (bool) $user->is_central_warehouse;

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
}

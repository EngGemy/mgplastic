<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\OtpTicketService;
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

        // Never issue tokens for deactivated accounts.
        if (! $user->is_active) {
            return response()->json([
                'status' => false,
                'message' => 'حسابك موقوف — تواصل مع الدعم',
                'code' => 'account_revoked',
            ], 403);
        }

        // Gate: phone must be verified via OTP before any Sanctum token.
        if (! $this->isPhoneVerified($user)) {
            $otp = app(PhoneOtpService::class);

            return response()->json([
                'status' => true,
                'message' => 'أكمل التحقق من الهاتف قبل الدخول',
                'code' => 'otp_required',
                'requires_otp' => true,
                'otp_sent' => false,
                'token' => null,
                'otp_ticket' => app(OtpTicketService::class)->issue($user),
                'otp_channels' => $otp->channelOptions(),
                'otp_ticket_ttl_minutes' => OtpTicketService::TTL_MINUTES,
                'user' => $this->userPayload($user),
                // Never return plaintext OTP — app must open channel-choice then OTP screen.
            ], 200);
        }

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
        }

        $hasBiometric = \App\Models\BiometricDevice::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        return response()->json([
            'status' => true,
            'message' => $message,
            'code' => 'login_ok',
            'requires_otp' => false,
            'token' => $token,
            'user' => $this->userPayload($user),
            'prompt_biometric' => ! $hasBiometric,
        ], 200);
    }

    protected function userPayload(User $user): array
    {
        $payload = $user->makeHidden(['otp_code', 'otp_expires_at'])->toArray();
        $payload['is_central_warehouse'] = (bool) $user->is_central_warehouse;
        $payload['is_phone_verified'] = $this->isPhoneVerified($user);

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

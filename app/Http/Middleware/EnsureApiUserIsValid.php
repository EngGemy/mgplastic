<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject deleted / inactive accounts on every API request that presents a Sanctum token.
 * Also deletes orphan personal_access_tokens (no matching user).
 */
class EnsureApiUserIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! filled($bearer)) {
            return $next($request);
        }

        $accessToken = null;
        try {
            $accessToken = PersonalAccessToken::findToken($bearer);
        } catch (\Throwable) {
            return $next($request);
        }

        if (! $accessToken) {
            return $next($request);
        }

        $tokenable = $accessToken->tokenable;

        // User row deleted (orphan token) or wrong morph target.
        if (! $tokenable instanceof User) {
            $accessToken->delete();

            return $this->revokedResponse();
        }

        if (! $this->isApiAllowed($tokenable)) {
            // Wipe every token for this account so the app cannot retry with another device token.
            $tokenable->tokens()->delete();

            return $this->revokedResponse();
        }

        if (! $this->isPhoneVerified($tokenable)) {
            $tokenable->tokens()->delete();

            return response()->json([
                'status' => false,
                'message' => 'يجب توثيق الهاتف قبل استخدام التطبيق',
                'code' => 'otp_required',
                'requires_otp' => true,
            ], 403);
        }

        return $next($request);
    }

    protected function isApiAllowed(User $user): bool
    {
        return User::query()
            ->whereKey($user->id)
            ->where('is_active', true)
            ->exists();
    }

    protected function isPhoneVerified(User $user): bool
    {
        return User::query()
            ->whereKey($user->id)
            ->where('is_phone_verified', true)
            ->exists();
    }

    protected function revokedResponse(): Response
    {
        return response()->json([
            'status' => false,
            'message' => 'حسابك موقوف أو تم حذفه — سجّل الدخول من جديد',
            'code' => 'account_revoked',
        ], 401);
    }
}

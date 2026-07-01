<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    use ApiResponds;

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['country', 'city']);

        return $this->success(new UserProfileResource($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $token?->delete();

        return $this->success(null, 'تم تسجيل الخروج');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return $this->success(null, 'تم تسجيل الخروج من جميع الأجهزة');
    }
}

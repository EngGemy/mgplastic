<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\HomeMobileLayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeLayoutController extends Controller
{
    use ApiResponds;

    /**
     * GET /api/v1/mobile/home/layout
     * Auth optional — role from Bearer token, else ?role= or guest.
     */
    public function __invoke(Request $request, HomeMobileLayoutService $layout): JsonResponse
    {
        // Optional Bearer — Sanctum resolves token without forcing login.
        $user = auth('sanctum')->user() ?? $request->user();
        $roleHint = $request->query('role');

        return $this->success(
            $layout->layoutPayload(is_string($roleHint) ? $roleHint : null, $user),
            'تخطيط الرئيسية للتطبيق'
        );
    }
}

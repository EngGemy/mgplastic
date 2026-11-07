<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\HomePopup;
use App\Services\HomeMobileLayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomePopupController extends Controller
{
    use ApiResponds;

    /**
     * GET /api/v1/mobile/home/popup
     * Auth optional — role from Bearer token, else ?role= or guest.
     */
    public function __invoke(Request $request, HomeMobileLayoutService $layout): JsonResponse
    {
        $user = auth('sanctum')->user() ?? $request->user();
        $roleHint = $request->query('role');
        $role = $layout->normalizeRole(is_string($roleHint) ? $roleHint : null, $user);

        $popup = HomePopup::pickForRole($role);

        if (! $popup) {
            return $this->success(null, 'لا يوجد إعلان');
        }

        return $this->success($popup->toMobilePayload($role), 'إعلان الرئيسية');
    }
}

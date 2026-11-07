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
        $locale = $this->localeFromRequest($request);

        return $this->success(
            $layout->layoutPayload(
                is_string($roleHint) ? $roleHint : null,
                $user,
                $locale
            ),
            'تخطيط الرئيسية للتطبيق'
        );
    }

    protected function localeFromRequest(Request $request): string
    {
        $raw = strtolower(str_replace('_', '-', (string) $request->header('Accept-Language', 'ar')));

        return str_starts_with($raw, 'en') ? 'en' : 'ar';
    }
}

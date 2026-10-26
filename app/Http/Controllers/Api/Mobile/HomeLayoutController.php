<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\HomeMobileLayoutService;
use Illuminate\Http\JsonResponse;

class HomeLayoutController extends Controller
{
    use ApiResponds;

    /** GET /api/v1/mobile/home/layout — عناوين السكاشن + البانر الأخضر + الأقسام. */
    public function __invoke(HomeMobileLayoutService $layout): JsonResponse
    {
        return $this->success($layout->layoutPayload(), 'تخطيط الرئيسية للتطبيق');
    }
}

<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\MobileDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponds;

    public function __invoke(Request $request): JsonResponse
    {
        return $this->success(MobileDashboardService::forPlumber($request->user()));
    }
}

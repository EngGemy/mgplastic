<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\MobileDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Professional points control panel for retail / wholesale store apps.
 *
 * GET /api/v1/mobile/trader/points-panel
 * GET /api/v1/mobile/distributor/points-panel
 */
class PointsPanelController extends Controller
{
    use ApiResponds;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isRetailTrader()) {
            return $this->success(
                MobileDashboardService::pointsPanelForRetail($user),
                'لوحة تحكم النقاط',
            );
        }

        if ($user->isWholesaleDistributor()) {
            return $this->success(
                MobileDashboardService::pointsPanelForWholesale($user),
                'لوحة تحكم النقاط',
            );
        }

        return $this->error('لوحة النقاط متاحة لمحلات الجملة والقطاعي فقط', 403);
    }
}

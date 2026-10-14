<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;

class WholesaleInvoiceStatsService
{
    /** @return array{count: int, total_points: int, distributed: int, remaining: int, percent: int} */
    public static function forDistributor(int $wholesaleDistributorId): array
    {
        $count = Invoice::query()
            ->approvedWholesale()
            ->where('wholesale_distributor_id', $wholesaleDistributorId)
            ->count();

        $wholesaler = User::query()->find($wholesaleDistributorId);
        $summary = $wholesaler?->isWholesaleDistributor()
            ? app(WholesalerNetworkService::class)->getSummary($wholesaler)
            : [];

        $totalPoints = (int) ($summary['factory_points'] ?? 0);
        $distributed = (int) ($summary['distributed_points'] ?? 0);
        $remaining = (int) ($summary['remaining_points'] ?? 0);
        $percent = $totalPoints > 0 ? (int) round(($distributed / $totalPoints) * 100) : 0;

        return [
            'count' => $count,
            'total_points' => $totalPoints,
            'distributed' => $distributed,
            'remaining' => $remaining,
            'percent' => $percent,
        ];
    }
}

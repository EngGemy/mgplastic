<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;

class WholesaleInvoiceStatsService
{
    /** @return array{count: int, total_points: int, distributed: int, remaining: int, percent: int} */
    public static function forDistributor(int $wholesaleDistributorId): array
    {
        $count = Invoice::query()
            ->approvedWholesale()
            ->where('wholesale_distributor_id', $wholesaleDistributorId)
            ->count();

        $totalPoints = (int) InvoiceItem::query()
            ->whereHas('invoice', fn ($q) => $q
                ->approvedWholesale()
                ->where('wholesale_distributor_id', $wholesaleDistributorId))
            ->sum('total_points');

        $distributed = (int) InvoiceDistributionItem::query()
            ->whereHas('distribution', fn ($q) => $q
                ->whereIn('status', ['confirmed', 'points_awarded'])
                ->whereHas('invoice', fn ($iq) => $iq
                    ->approvedWholesale()
                    ->where('wholesale_distributor_id', $wholesaleDistributorId)))
            ->sum('points_value');

        $remaining = max(0, $totalPoints - $distributed);
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

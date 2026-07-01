<?php

namespace App\Filament\Widgets;

use App\Models\InvoiceDistribution;
use App\Models\WalletAccount;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DistributionSummaryWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    protected static ?string $pollingInterval = '30s';

    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $totalPointsAwarded = WalletAccount::sum('balance_points');
        $pendingDistributions = InvoiceDistribution::where('status', 'draft')->count();
        $confirmedToday = InvoiceDistribution::where('status', 'confirmed')
            ->whereDate('confirmed_at', today())
            ->count();
        $plumbersWithPoints = WalletAccount::where('balance_points', '>', 0)->count();

        return [
            Stat::make('إجمالي النقاط الممنوحة', number_format($totalPointsAwarded))
                ->description('لجميع السباكين')
                ->descriptionIcon('heroicon-o-star')
                ->color('success')
                ->chart([7, 4, 6, 8, 12, 10, 14]),

            Stat::make('توزيعات قيد الانتظار', $pendingDistributions)
                ->description('مسودات لم تُؤكد بعد')
                ->descriptionIcon('heroicon-o-clock')
                ->color($pendingDistributions > 0 ? 'warning' : 'gray'),

            Stat::make('توزيعات مؤكدة اليوم', $confirmedToday)
                ->description('خلال اليوم الحالي')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('primary'),

            Stat::make('سباكون لديهم نقاط', $plumbersWithPoints)
                ->description('رصيد نشط')
                ->descriptionIcon('heroicon-o-users')
                ->color('info'),
        ];
    }
}

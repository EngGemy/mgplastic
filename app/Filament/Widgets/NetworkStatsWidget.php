<?php

namespace App\Filament\Widgets;

use App\Models\InvoiceDistribution;
use App\Services\RetailTraderNetworkService;
use App\Services\WholesalerNetworkService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class NetworkStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['wholesale_distributor', 'retail_trader'], true);
    }

    protected function getHeading(): ?string
    {
        return 'نظرة عامة — حسابي';
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user?->isWholesaleDistributor()) {
            return $this->wholesalerStats($user);
        }

        if ($user?->isRetailTrader()) {
            return $this->retailTraderStats($user);
        }

        return [];
    }

    private function wholesalerStats($user): array
    {
        $summary = app(WholesalerNetworkService::class)->getSummary($user);
        $pending = $this->scopedPendingCount($user->id);
        $confirmedToday = $this->scopedConfirmedTodayCount($user->id);

        return [
            Stat::make('رصيد النقاط', number_format($summary['balance_points']))
                ->description('الرصيد الحالي في محفظتك')
                ->descriptionIcon('heroicon-o-wallet')
                ->color('success'),

            Stat::make('نقاط من المصنع', number_format($summary['factory_points']))
                ->description('إجمالي ما استلمته من المصنع')
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('primary'),

            Stat::make('موزَّع للقطاعي', number_format($summary['distributed_points']))
                ->description('نقاط مخصومة عند البيع للقطاعي')
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color('warning'),

            Stat::make('وحدات منتجات', number_format($summary['product_units']))
                ->description($summary['product_types'].' صنف متاح للتوزيع')
                ->descriptionIcon('heroicon-o-cube')
                ->color('info'),

            Stat::make('تجار قطاعي', number_format($summary['retail_traders_count']))
                ->description('تجار تحت شبكتك')
                ->descriptionIcon('heroicon-o-building-office')
                ->color('gray'),

            Stat::make('توزيعات قيد الانتظار', $pending)
                ->description('مسودات لم تُؤكد بعد')
                ->descriptionIcon('heroicon-o-clock')
                ->color($pending > 0 ? 'warning' : 'gray'),

            Stat::make('توزيعات مؤكدة اليوم', $confirmedToday)
                ->description('خلال اليوم الحالي')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('primary'),
        ];
    }

    private function retailTraderStats($user): array
    {
        $summary = app(RetailTraderNetworkService::class)->getSummary($user);
        $pending = $this->scopedPendingCount($user->id);
        $confirmedToday = $this->scopedConfirmedTodayCount($user->id);

        return [
            Stat::make('رصيد النقاط', number_format($summary['balance_points']))
                ->description('الرصيد الحالي في محفظتك')
                ->descriptionIcon('heroicon-o-wallet')
                ->color('success'),

            Stat::make('نقاط من الجملة', number_format($summary['received_points']))
                ->description('إجمالي ما استلمته من موزع الجملة')
                ->descriptionIcon('heroicon-o-building-storefront')
                ->color('primary'),

            Stat::make('موزَّع للسباكين', number_format($summary['distributed_points']))
                ->description('نقاط مخصومة عند البيع للسباك')
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color('warning'),

            Stat::make('وحدات منتجات', number_format($summary['product_units']))
                ->description('متاحة للتوزيع على السباكين')
                ->descriptionIcon('heroicon-o-cube')
                ->color('info'),

            Stat::make('السباكون', number_format($summary['plumbers_count']))
                ->description('سباكون تحت شبكتك')
                ->descriptionIcon('heroicon-o-wrench-screwdriver')
                ->color('gray'),

            Stat::make('توزيعات قيد الانتظار', $pending)
                ->description('مسودات لم تُؤكد بعد')
                ->descriptionIcon('heroicon-o-clock')
                ->color($pending > 0 ? 'warning' : 'gray'),

            Stat::make('توزيعات مؤكدة اليوم', $confirmedToday)
                ->description('خلال اليوم الحالي')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('primary'),
        ];
    }

    private function scopedPendingCount(int $userId): int
    {
        return InvoiceDistribution::query()
            ->where('status', 'draft')
            ->where(function ($q) use ($userId) {
                $q->where('from_user_id', $userId)
                    ->orWhere('to_user_id', $userId);
            })
            ->count();
    }

    private function scopedConfirmedTodayCount(int $userId): int
    {
        return InvoiceDistribution::query()
            ->where('status', 'confirmed')
            ->whereDate('confirmed_at', today())
            ->where(function ($q) use ($userId) {
                $q->where('from_user_id', $userId)
                    ->orWhere('to_user_id', $userId);
            })
            ->count();
    }
}

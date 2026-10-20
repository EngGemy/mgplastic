<?php

namespace App\Filament\Widgets;

use App\Models\PlumberScore;
use App\Models\PlumberStreak;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReputationStatsWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin', 'monitor'], true);
    }

    protected function getHeading(): ?string
    {
        return 'السمعة والضمان';
    }

    protected function getStats(): array
    {
        $levels = PlumberScore::query()->selectRaw('level, count(*) as c')->groupBy('level')->pluck('c', 'level');
        $activeStreaks = PlumberStreak::query()->where('current_weeks', '>', 0)->count();
        $monthWarranties = Warranty::query()->where('issued_at', '>=', now()->startOfMonth())->count();
        $openClaims = WarrantyClaim::query()->whereIn('status', ['open', 'in_review'])->count();
        $top = PlumberScore::query()->with('user')->orderByDesc('score')->limit(1)->first();

        return [
            Stat::make('ذهبي+', number_format((int) ($levels['gold'] ?? 0) + (int) ($levels['diamond'] ?? 0)))
                ->description('برونزي '.$levels->get('bronze', 0).' · فضي '.$levels->get('silver', 0)),
            Stat::make('سلاسل نشطة', number_format($activeStreaks)),
            Stat::make('ضمانات الشهر', number_format($monthWarranties)),
            Stat::make('مطالبات مفتوحة', number_format($openClaims))
                ->color($openClaims > 0 ? 'danger' : 'gray'),
            Stat::make('أعلى درجة', (string) ($top?->score ?? 0))
                ->description($top?->user?->name ?: '—'),
        ];
    }
}

<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class UsersPerDayChart extends ChartWidget
{
    protected static bool $isDiscovered = false;
    protected static ?string $heading = 'New Users (last 30 days)';
    protected static ?string $maxHeight = '240px';

    protected function getData(): array
    {
        $from = Carbon::today()->subDays(29);

        $rows = User::selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->whereDate('created_at', '>=', $from)
            ->groupBy('d')
            ->orderBy('d')
            ->pluck('c', 'd')
            ->all();

        $labels = [];
        $data   = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $labels[] = $day;
            $data[]   = $rows[$day] ?? 0;
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Users', 'data' => $data],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}

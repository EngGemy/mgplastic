<?php

namespace App\Filament\Distributor\Pages;

use App\Models\SystemLabel;
use Filament\Pages\Dashboard as BaseDashboard;

class DistributorDashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -2;

    public static function getNavigationLabel(): string
    {
        return SystemLabel::get('dashboard', 'لوحة التحكم');
    }

    public function getTitle(): string
    {
        return '';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\MgCrmDashboardWidget::class,
            \App\Filament\Widgets\QuickAccessWidget::class,
        ];
    }

    public function getWidgets(): array
    {
        return [];
    }
}

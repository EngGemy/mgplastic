<?php

namespace App\Filament\Pages;

use App\Models\SystemLabel;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
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
            \App\Filament\Widgets\GeneralControlsWidget::class,
            \App\Filament\Widgets\QuickAccessWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [];
    }

    /**
     * منع تكرار الـ widgets المسجّلة في Panel داخل جسم الصفحة
     * (الـ header/footer يعرضانها عبر layout الصفحة).
     */
    public function getWidgets(): array
    {
        return [];
    }
}

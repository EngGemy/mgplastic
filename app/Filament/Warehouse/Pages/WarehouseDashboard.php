<?php

namespace App\Filament\Warehouse\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class WarehouseDashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = -10;

    protected static ?string $navigationGroup = 'لوحة المخزن';

    public static function getNavigationLabel(): string
    {
        return 'لوحة تحكم المخزن';
    }

    public function getTitle(): string
    {
        return 'مخزن الدعوة — لوحة التحكم';
    }

    public function getHeading(): string
    {
        return 'مخزن الدعوة';
    }

    public function getSubheading(): ?string
    {
        return 'استقبال الطلبيات · المخزون · النقاط · الطلب من المصنع · شبكة الجملة والقطاعي';
    }

    protected function getHeaderWidgets(): array
    {
        // CRM already includes points/stock cards — skip duplicate points panel.
        return [
            \App\Filament\Widgets\MgCrmDashboardWidget::class,
            \App\Filament\Warehouse\Widgets\WarehouseQuickLinksWidget::class,
            \App\Filament\Widgets\LowStockAlertWidget::class,
        ];
    }

    public function getWidgets(): array
    {
        return [];
    }
}

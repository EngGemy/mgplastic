<?php

namespace App\Filament\Distributor\Pages;

use App\Filament\Concerns\HasNetworkStockInventoryReport;
use Filament\Pages\Page;

class StockInventoryReport extends Page
{
    use HasNetworkStockInventoryReport;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'تقرير المخزون';

    protected static ?string $title = 'تقرير المخزون والتوزيع';

    protected static ?string $navigationGroup = 'المخزون والنقاط';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.stock-inventory-report';

    protected function reportRole(): string
    {
        return 'wholesale';
    }

    public static function getNavigationGroup(): ?string
    {
        return filament()->getCurrentPanel()?->getId() === 'warehouse'
            ? 'المخزون والنقاط'
            : 'نظام النقاط';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && $user->isWholesaleDistributor()
            && static::canAccessReport();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}

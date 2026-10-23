<?php

namespace App\Filament\Trader\Pages;

use App\Filament\Concerns\HasNetworkStockInventoryReport;
use Filament\Pages\Page;

class StockInventoryReport extends Page
{
    use HasNetworkStockInventoryReport;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'تقرير المخزون';

    protected static ?string $title = 'تقرير المخزون والتوزيع';

    protected static ?string $navigationGroup = 'نظام النقاط';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.stock-inventory-report';

    protected function reportRole(): string
    {
        return 'retail';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && $user->isRetailTrader()
            && static::canAccessReport();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}

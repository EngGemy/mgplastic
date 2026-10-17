<?php

namespace App\Filament\Distributor\Pages;

use App\Filament\Concerns\ManagesMyStoreProducts;
use App\Support\StorePermissions;
use Filament\Pages\Page;

class MyProducts extends Page
{
    use ManagesMyStoreProducts;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'منتجاتي';

    protected static ?string $title = 'منتجاتي';

    protected static ?string $navigationGroup = 'متجري';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.my-products';

    public static function getNavigationGroup(): ?string
    {
        return filament()->getCurrentPanel()?->getId() === 'warehouse'
            ? 'واجهة المحل'
            : 'متجري';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && $user->isWholesaleDistributor()
            && $user->canStorePermission(StorePermissions::CATALOG_MANAGE);
    }
}

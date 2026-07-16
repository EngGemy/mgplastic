<?php

namespace App\Filament\Trader\Pages;

use App\Filament\Concerns\ManagesMyStoreProducts;
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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->isRetailTrader();
    }
}

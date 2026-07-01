<?php

namespace App\Filament\Resources\RetailTraderResource\Pages;

use App\Filament\Resources\RetailTraderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRetailTraders extends ListRecords
{
    protected static string $resource = RetailTraderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('إضافة موزع قطاعي')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}

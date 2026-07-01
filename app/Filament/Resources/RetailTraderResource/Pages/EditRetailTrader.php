<?php

namespace App\Filament\Resources\RetailTraderResource\Pages;

use App\Filament\Resources\RetailTraderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRetailTrader extends EditRecord
{
    protected static string $resource = RetailTraderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('عرض'),
            Actions\DeleteAction::make()->label('حذف'),
        ];
    }
}

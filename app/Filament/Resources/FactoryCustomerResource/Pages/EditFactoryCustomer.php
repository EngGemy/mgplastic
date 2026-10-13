<?php

namespace App\Filament\Resources\FactoryCustomerResource\Pages;

use App\Filament\Resources\FactoryCustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFactoryCustomer extends EditRecord
{
    protected static string $resource = FactoryCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('حذف'),
        ];
    }
}

<?php

namespace App\Filament\Resources\FactoryCustomerResource\Pages;

use App\Filament\Resources\FactoryCustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFactoryCustomers extends ListRecords
{
    protected static string $resource = FactoryCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('عميل جديد'),
        ];
    }
}

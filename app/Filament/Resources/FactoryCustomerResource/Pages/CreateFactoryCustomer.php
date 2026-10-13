<?php

namespace App\Filament\Resources\FactoryCustomerResource\Pages;

use App\Filament\Resources\FactoryCustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFactoryCustomer extends CreateRecord
{
    protected static string $resource = FactoryCustomerResource::class;

    protected static ?string $title = 'عميل مصنع جديد';
}

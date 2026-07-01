<?php

namespace App\Filament\Resources\InvoiceDistributionResource\Pages;

use App\Filament\Resources\InvoiceDistributionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInvoiceDistributions extends ListRecords
{
    protected static string $resource = InvoiceDistributionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('توزيع جديد'),
        ];
    }
}

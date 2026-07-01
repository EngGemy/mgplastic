<?php

namespace App\Filament\Trader\Resources\TraderInvoiceResource\Pages;

use App\Filament\Trader\Resources\TraderInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListTraderInvoices extends ListRecords
{
    protected static string $resource = TraderInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

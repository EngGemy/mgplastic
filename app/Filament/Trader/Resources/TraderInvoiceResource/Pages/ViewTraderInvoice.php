<?php

namespace App\Filament\Trader\Resources\TraderInvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Trader\Resources\TraderInvoiceResource;

class ViewTraderInvoice extends ViewInvoice
{
    protected static string $resource = TraderInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->invoiceDocumentHeaderActions(),
        ];
    }
}

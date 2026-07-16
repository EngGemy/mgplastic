<?php

namespace App\Filament\Trader\Resources\TraderInvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Trader\Resources\TraderInvoiceResource;
use Filament\Actions;

class ViewTraderInvoice extends ViewInvoice
{
    protected static string $resource = TraderInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print_invoice')
                ->label('طباعة الفاتورة')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('admin.invoices.print', $this->record))
                ->openUrlInNewTab(),
        ];
    }
}

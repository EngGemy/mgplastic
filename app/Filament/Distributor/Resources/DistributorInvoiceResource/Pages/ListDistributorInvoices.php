<?php

namespace App\Filament\Distributor\Resources\DistributorInvoiceResource\Pages;

use App\Filament\Distributor\Resources\DistributorInvoiceResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDistributorInvoices extends ListRecords
{
    protected static string $resource = DistributorInvoiceResource::class;

    public function getTabs(): array
    {
        return [
            'incoming' => Tab::make('الوارد — من المصنع')
                ->icon('heroicon-o-arrow-down-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('invoice_flow', 'incoming')),

            'outgoing' => Tab::make('الصادر — للقطاعي')
                ->icon('heroicon-o-arrow-up-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('invoice_flow', 'outgoing')),

            'all' => Tab::make('الكل')
                ->icon('heroicon-o-queue-list'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'incoming';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'الفواتير';
    }
}

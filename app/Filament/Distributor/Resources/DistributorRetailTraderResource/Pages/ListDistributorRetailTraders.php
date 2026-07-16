<?php

namespace App\Filament\Distributor\Resources\DistributorRetailTraderResource\Pages;

use App\Filament\Distributor\Resources\DistributorRetailTraderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDistributorRetailTraders extends ListRecords
{
    protected static string $resource = DistributorRetailTraderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('إضافة تاجر قطاعي')
                ->icon('heroicon-o-plus-circle')
                ->url(DistributorRetailTraderResource::getUrl('create')),
        ];
    }
}

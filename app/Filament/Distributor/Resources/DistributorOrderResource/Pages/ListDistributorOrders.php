<?php

namespace App\Filament\Distributor\Resources\DistributorOrderResource\Pages;

use App\Filament\Distributor\Resources\DistributorOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDistributorOrders extends ListRecords
{
    protected static string $resource = DistributorOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('طلب جديد من المصنع')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}

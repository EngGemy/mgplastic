<?php

namespace App\Filament\Trader\Resources\TraderOrderResource\Pages;

use App\Filament\Trader\Resources\TraderOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTraderOrders extends ListRecords
{
    protected static string $resource = TraderOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('طلب جديد من موزّع الجملة')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}

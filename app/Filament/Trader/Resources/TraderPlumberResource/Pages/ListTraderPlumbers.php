<?php

namespace App\Filament\Trader\Resources\TraderPlumberResource\Pages;

use App\Filament\Trader\Resources\TraderPlumberResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTraderPlumbers extends ListRecords
{
    protected static string $resource = TraderPlumberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إضافة سباك جديد'),
        ];
    }
}

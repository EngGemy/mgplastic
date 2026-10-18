<?php

namespace App\Filament\Resources\ProductPromotionResource\Pages;

use App\Filament\Resources\ProductPromotionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductPromotions extends ListRecords
{
    protected static string $resource = ProductPromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إضافة إعلان'),
        ];
    }
}

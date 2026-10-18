<?php

namespace App\Filament\Resources\ProductPromotionResource\Pages;

use App\Filament\Resources\ProductPromotionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductPromotion extends EditRecord
{
    protected static string $resource = ProductPromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

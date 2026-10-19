<?php

namespace App\Filament\Resources\PromoBannerResource\Pages;

use App\Filament\Resources\PromoBannerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPromoBanner extends EditRecord
{
    protected static string $resource = PromoBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\HomePopupResource\Pages;

use App\Filament\Resources\HomePopupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHomePopups extends ListRecords
{
    protected static string $resource = HomePopupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إضافة إعلان'),
        ];
    }

    public function getTitle(): string
    {
        return 'إعلانات الرئيسية';
    }
}

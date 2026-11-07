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
            Actions\CreateAction::make()
                ->label('بوب أب جديد')
                ->icon('heroicon-o-plus'),
        ];
    }

    public function getTitle(): string
    {
        return 'بوب أب الرئيسية';
    }

    public function getHeading(): string
    {
        return 'بوب أب الرئيسية';
    }

    public function getSubheading(): ?string
    {
        return 'إعلان أو تصويت يظهر أول ما المستخدم يفتح الرئيسية — مضمّن في GET home/layout → data.popup';
    }
}

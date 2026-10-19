<?php

namespace App\Filament\Resources\PromoBannerResource\Pages;

use App\Filament\Resources\PromoBannerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPromoBanner extends EditRecord
{
    protected static string $resource = PromoBannerResource::class;

    public function getTitle(): string
    {
        return 'تعديل بانر دعائي';
    }

    public function getHeading(): string
    {
        return 'تعديل: '.$this->record->title;
    }

    public function getSubheading(): ?string
    {
        return 'سكشن: بين السباكين والمتاجر';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('حذف البانر'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم تحديث البانر';
    }
}

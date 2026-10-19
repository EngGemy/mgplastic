<?php

namespace App\Filament\Resources\ProductCollectionResource\Pages;

use App\Filament\Resources\ProductCollectionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductCollection extends EditRecord
{
    protected static string $resource = ProductCollectionResource::class;

    public function getTitle(): string
    {
        return 'تعديل مجموعة منتجات';
    }

    public function getHeading(): string
    {
        return 'تعديل: '.$this->record->title;
    }

    public function getSubheading(): ?string
    {
        return 'غيّر الاسم أو المنتجات أو التصنيف في أي وقت — يظهر فوراً في التطبيق.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('حذف المجموعة'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم تحديث المجموعة';
    }
}

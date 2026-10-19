<?php

namespace App\Filament\Resources\ProductPromotionResource\Pages;

use App\Filament\Resources\ProductPromotionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductPromotion extends EditRecord
{
    protected static string $resource = ProductPromotionResource::class;

    public function getTitle(): string
    {
        return 'تعديل إعلان الدعاية';
    }

    public function getHeading(): string
    {
        return 'تعديل: '.$this->record->title;
    }

    public function getSubheading(): ?string
    {
        return match ($this->record->media_type) {
            'video' => 'نوع المحتوى: فيديو',
            'comparison' => 'نوع المحتوى: مقارنة صورتين',
            default => 'نوع المحتوى: صورة',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('حذف الإعلان'),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم تحديث الإعلان';
    }
}

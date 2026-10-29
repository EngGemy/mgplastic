<?php

namespace App\Filament\Resources\ProductTagImageResource\Pages;

use App\Filament\Resources\ProductTagImageResource;
use App\Models\ProductTag;
use Filament\Resources\Pages\CreateRecord;

class CreateProductTagImage extends CreateRecord
{
    protected static string $resource = ProductTagImageResource::class;

    public function getTitle(): string
    {
        return 'إضافة صورة تاج';
    }

    protected function afterCreate(): void
    {
        /** @var ProductTag $record */
        $record = $this->record;
        ProductTagImageResource::syncImageToSameNameTags($record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم حفظ صورة التاج';
    }
}

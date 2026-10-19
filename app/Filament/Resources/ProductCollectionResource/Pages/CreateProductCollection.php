<?php

namespace App\Filament\Resources\ProductCollectionResource\Pages;

use App\Filament\Resources\ProductCollectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductCollection extends CreateRecord
{
    protected static string $resource = ProductCollectionResource::class;

    public function getTitle(): string
    {
        return 'إضافة مجموعة منتجات';
    }

    public function getHeading(): string
    {
        return 'إضافة مجموعة منتجات';
    }

    public function getSubheading(): ?string
    {
        return 'سمّي المجموعة بأي اسم تبيه، اختر المنتجات، وانشرها في التطبيق — يمكن التعديل لاحقاً.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم حفظ المجموعة';
    }
}

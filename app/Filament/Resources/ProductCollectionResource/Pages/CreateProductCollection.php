<?php

namespace App\Filament\Resources\ProductCollectionResource\Pages;

use App\Filament\Resources\ProductCollectionResource;
use App\Models\ProductCollection;
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug'] = ProductCollection::uniqueSlugFor(
            (string) ($data['slug'] ?? ''),
            (string) ($data['title'] ?? ''),
        );

        return $data;
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

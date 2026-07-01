<?php

namespace App\Filament\Resources\ProductCategoryResource\Pages;

use App\Filament\Resources\ProductCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductCategory extends CreateRecord
{
    protected static string $resource = ProductCategoryResource::class;

    // Create base + parent_id; translations after create
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            'slug'      => $data['slug'] ?? null,
            'image'     => $data['image'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
        ];
    }

    protected function afterCreate(): void
    {
        $record = $this->record;
        $data   = $this->form->getState();

        $record->translateOrNew('en')->name        = $data['name_en'] ?? '';
        $record->translateOrNew('en')->description = $data['description_en'] ?? '';
        $record->translateOrNew('ar')->name        = $data['name_ar'] ?? '';
        $record->translateOrNew('ar')->description = $data['description_ar'] ?? '';
        $record->save();
    }
}

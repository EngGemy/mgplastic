<?php

namespace App\Filament\Resources\ProductCategoryResource\Pages;

use App\Filament\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateProductCategory extends CreateRecord
{
    protected static string $resource = ProductCategoryResource::class;

    // Create base + parent_id; translations after create
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $parentId = ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        if (ProductCategory::parentWouldCreateCycle(null, $parentId)) {
            throw ValidationException::withMessages([
                'parent_id' => ['لا يمكن اختيار أب يُنشئ حلقة في شجرة التصنيفات.'],
            ]);
        }

        return [
            'slug' => $data['slug'] ?? null,
            'image' => $data['image'] ?? null,
            'parent_id' => $parentId,
        ];
    }

    protected function afterCreate(): void
    {
        $record = $this->record;
        $data = $this->form->getState();

        $record->translateOrNew('en')->name = $data['name_en'] ?? '';
        $record->translateOrNew('en')->description = $data['description_en'] ?? '';
        $record->translateOrNew('ar')->name = $data['name_ar'] ?? '';
        $record->translateOrNew('ar')->description = $data['description_ar'] ?? '';
        $record->save();
        $record->syncOwnedTagNames($data['tag_names'] ?? []);
    }
}

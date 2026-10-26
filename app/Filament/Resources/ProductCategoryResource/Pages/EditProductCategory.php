<?php

namespace App\Filament\Resources\ProductCategoryResource\Pages;

use App\Filament\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditProductCategory extends EditRecord
{
    protected static string $resource = ProductCategoryResource::class;

    // Prefill pseudo translation fields
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ProductCategory $record */
        $record = $this->record;

        $data['name_en'] = optional($record->translate('en'))->name;
        $data['description_en'] = optional($record->translate('en'))->description;
        $data['name_ar'] = optional($record->translate('ar'))->name;
        $data['description_ar'] = optional($record->translate('ar'))->description;
        $data['tag_names'] = $record->tags()->orderBy('name')->pluck('name')->all();

        return $data;
    }

    // Include parent_id + base fields in mass update; translations after save
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var ProductCategory $record */
        $record = $this->record;

        $parentId = ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        if (ProductCategory::parentWouldCreateCycle((int) $record->id, $parentId)) {
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

    protected function afterSave(): void
    {
        /** @var ProductCategory $record */
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

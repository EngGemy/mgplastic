<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load([
            'translations',
            'category.parent.translations',
            'category.translations',
            'images',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('تعديل المنتج'),
        ];
    }
}

<?php

namespace App\Filament\Resources\ProductCollectionResource\Pages;

use App\Filament\Resources\ProductCollectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductCollections extends ListRecords
{
    protected static string $resource = ProductCollectionResource::class;

    public function getTitle(): string
    {
        return 'مجموعات المنتجات';
    }

    public function getHeading(): string
    {
        return 'مجموعات المنتجات';
    }

    public function getSubheading(): ?string
    {
        return 'مجموعات بأسماء حرّة تظهر في التطبيق — اضغط تعديل لتغيير الاسم أو المنتجات.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إضافة مجموعة'),
        ];
    }
}

<?php

namespace App\Filament\Resources\ProductTagImageResource\Pages;

use App\Filament\Resources\ProductTagImageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductTagImages extends ListRecords
{
    protected static string $resource = ProductTagImageResource::class;

    public function getTitle(): string
    {
        return 'صوره التاجات';
    }

    public function getHeading(): string
    {
        return 'صوره التاجات';
    }

    public function getSubheading(): ?string
    {
        return 'ارفع صورة لكل مقاس/وسم (1 بوصه، 2 بوصه…) — تظهر في أكورديون المنتجات بالتطبيق.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إضافة تاج + صورة'),
        ];
    }
}

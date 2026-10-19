<?php

namespace App\Filament\Resources\ProductPromotionResource\Pages;

use App\Filament\Resources\ProductPromotionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductPromotions extends ListRecords
{
    protected static string $resource = ProductPromotionResource::class;

    public function getTitle(): string
    {
        return 'دعاية منتجات MG';
    }

    public function getHeading(): string
    {
        return 'دعاية منتجات MG';
    }

    public function getSubheading(): ?string
    {
        return 'إعلانات صورة / فيديو / مقارنة تظهر في التطبيق عبر product-promotions.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('إضافة إعلان')
                ->icon('heroicon-o-plus'),
        ];
    }
}

<?php

namespace App\Filament\Resources\PromoBannerResource\Pages;

use App\Filament\Resources\PromoBannerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPromoBanners extends ListRecords
{
    protected static string $resource = PromoBannerResource::class;

    public function getTitle(): string
    {
        return 'بانرات دعائية';
    }

    public function getHeading(): string
    {
        return 'بانرات دعائية';
    }

    public function getSubheading(): ?string
    {
        return 'تظهر بين السباكين والمتاجر عبر promo-banners.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('إضافة بانر')
                ->icon('heroicon-o-plus'),
        ];
    }
}

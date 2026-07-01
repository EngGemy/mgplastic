<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('points_report')
                ->label('تقرير النقاط')
                ->icon('heroicon-o-presentation-chart-bar')
                ->color('info')
                ->url(fn () => \App\Filament\Pages\ProductsReport::getUrl()),

            Actions\CreateAction::make()
                ->label('إضافة منتج')
                ->url(fn () => ProductResource::getUrl('create')),
        ];
    }
}

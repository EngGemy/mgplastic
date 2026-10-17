<?php

namespace App\Filament\Warehouse\Resources\WarehouseWholesalerResource\Pages;

use App\Filament\Warehouse\Pages\WarehouseReports;
use App\Filament\Warehouse\Resources\WarehouseWholesalerResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewWarehouseWholesaler extends ViewRecord
{
    protected static string $resource = WarehouseWholesalerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('report')
                ->label('تقرير هذا المخزن')
                ->icon('heroicon-o-chart-bar')
                ->url(fn () => WarehouseReports::getUrl().'?warehouse_id='.$this->record->id),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('بيانات المخزن')->schema([
                Infolists\Components\TextEntry::make('display_name')
                    ->label('اسم المخزن')
                    ->state(fn ($record) => trim((string) ($record->brand_name ?: $record->name)) ?: '—')
                    ->copyable()
                    ->copyMessage('تم نسخ اسم المخزن'),
                Infolists\Components\TextEntry::make('brand_name')
                    ->label('العلامة')
                    ->placeholder('—')
                    ->visible(fn ($record) => filled($record->brand_name)),
                Infolists\Components\TextEntry::make('name')
                    ->label('الاسم')
                    ->copyable(),
                Infolists\Components\TextEntry::make('network_code')
                    ->label('الرقم الموحّد')
                    ->copyable()
                    ->copyMessage('تم نسخ الرقم الموحّد'),
                Infolists\Components\TextEntry::make('phone')->label('الهاتف')->copyable(),
                Infolists\Components\TextEntry::make('email')->label('البريد')->copyable(),
                Infolists\Components\TextEntry::make('city.name')->label('المدينة')->placeholder('—'),
                Infolists\Components\IconEntry::make('is_approved')->label('معتمد')->boolean(),
                Infolists\Components\IconEntry::make('is_active')->label('نشط')->boolean(),
            ])->columns(2),
        ]);
    }
}

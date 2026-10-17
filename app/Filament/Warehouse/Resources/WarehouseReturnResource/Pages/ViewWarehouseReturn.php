<?php

namespace App\Filament\Warehouse\Resources\WarehouseReturnResource\Pages;

use App\Filament\Warehouse\Resources\WarehouseReturnResource;
use App\Support\InvoiceReturnStatus;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewWarehouseReturn extends ViewRecord
{
    protected static string $resource = WarehouseReturnResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('بيانات المرتجع')->schema([
                Infolists\Components\TextEntry::make('return_number')->label('الرقم'),
                Infolists\Components\TextEntry::make('status')
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state) => InvoiceReturnStatus::label(InvoiceReturnStatus::normalize($state)))
                    ->badge()
                    ->color(fn ($state) => InvoiceReturnStatus::color(InvoiceReturnStatus::normalize($state))),
                Infolists\Components\TextEntry::make('tier')
                    ->label('المسار')
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        2 => 'جملة → قطاعي',
                        3 => 'قطاعي → سباك',
                        default => '#'.$state,
                    }),
                Infolists\Components\TextEntry::make('total_quantity')->label('الكمية'),
                Infolists\Components\TextEntry::make('total_points')->label('النقاط'),
                Infolists\Components\TextEntry::make('fromUser.brand_name')->label('من'),
                Infolists\Components\TextEntry::make('toUser.brand_name')->label('إلى'),
                Infolists\Components\TextEntry::make('note')->label('ملاحظة')->columnSpanFull(),
            ])->columns(2),
            Infolists\Components\RepeatableEntry::make('items')
                ->label('البنود')
                ->schema([
                    Infolists\Components\TextEntry::make('product_id')->label('المنتج')
                        ->formatStateUsing(fn ($state, $record) => localized_name($record->product, 'name', 'منتج #'.$state)),
                    Infolists\Components\TextEntry::make('quantity')->label('الكمية'),
                    Infolists\Components\TextEntry::make('points_value')->label('النقاط'),
                ])
                ->columns(3),
        ]);
    }
}

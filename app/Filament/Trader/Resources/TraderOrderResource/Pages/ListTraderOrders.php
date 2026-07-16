<?php

namespace App\Filament\Trader\Resources\TraderOrderResource\Pages;

use App\Filament\Trader\Resources\TraderOrderResource;
use App\Support\OrderStatus;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTraderOrders extends ListRecords
{
    protected static string $resource = TraderOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('طلب جديد من موزّع الجملة')
                ->icon('heroicon-o-plus-circle')
                ->color('primary'),
        ];
    }

    public function getTitle(): string
    {
        return 'الطلبيات';
    }

    public function getTabs(): array
    {
        $userId = auth()->id();

        return [
            'all' => Tab::make('الكل'),

            'from_plumbers' => Tab::make('طلبات السباكين')
                ->icon('heroicon-o-wrench-screwdriver')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('channel', OrderStatus::CHANNEL_RETAIL_TO_PLUMBER)
                    ->where('supplier_id', $userId))
                ->badge(fn () => TraderOrderResource::getEloquentQuery()
                    ->where('channel', OrderStatus::CHANNEL_RETAIL_TO_PLUMBER)
                    ->where('supplier_id', $userId)
                    ->where('status', OrderStatus::PLACED)
                    ->count())
                ->badgeColor('success'),

            'to_wholesaler' => Tab::make('طلباتي للجملة')
                ->icon('heroicon-o-truck')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('channel', OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL)
                    ->where('requester_id', $userId)),
        ];
    }
}

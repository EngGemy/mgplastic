<?php

namespace App\Filament\Distributor\Resources\DistributorOrderResource\Pages;

use App\Filament\Distributor\Resources\DistributorOrderResource;
use App\Support\OrderStatus;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDistributorOrders extends ListRecords
{
    protected static string $resource = DistributorOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('طلب جديد من المصنع')
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

            'from_retail' => Tab::make('طلبات القطاعي')
                ->icon('heroicon-o-building-storefront')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('channel', OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL)
                    ->where('supplier_id', $userId))
                ->badge(fn () => DistributorOrderResource::getEloquentQuery()
                    ->where('channel', OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL)
                    ->where('supplier_id', $userId)
                    ->where('status', OrderStatus::PLACED)
                    ->count())
                ->badgeColor('warning'),

            'to_factory' => Tab::make('طلباتي للمصنع')
                ->icon('heroicon-o-truck')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('channel', OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE)
                    ->where('requester_id', $userId)),

            'needs_action' => Tab::make('تحتاج إجراء')
                ->icon('heroicon-o-bell-alert')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('supplier_id', $userId)
                    ->whereIn('status', [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPING]))
                ->badge(fn () => DistributorOrderResource::getEloquentQuery()
                    ->where('supplier_id', $userId)
                    ->whereIn('status', [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPING])
                    ->count())
                ->badgeColor('danger'),
        ];
    }
}

<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Support\OrderStatus;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('الكل')
                ->badge(Order::query()->count()),

            'placed' => Tab::make('بانتظار التأكيد')
                ->icon('heroicon-o-clock')
                ->badge(Order::query()->where('status', OrderStatus::PLACED)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::PLACED)),

            'shipping' => Tab::make('في الطريق')
                ->icon('heroicon-o-truck')
                ->badge(Order::query()->where('status', OrderStatus::SHIPPING)->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::SHIPPING)),

            'delivered' => Tab::make('تم التسليم')
                ->icon('heroicon-o-check-badge')
                ->badge(Order::query()->where('status', OrderStatus::DELIVERED)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::DELIVERED)),

            'closed' => Tab::make('ملغي / مرفوض')
                ->icon('heroicon-o-x-circle')
                ->badge(Order::query()->whereIn('status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])),
        ];
    }
}

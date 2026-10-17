<?php

namespace App\Filament\Warehouse\Resources\WarehouseReturnResource\Pages;

use App\Filament\Warehouse\Resources\WarehouseReturnResource;
use App\Support\InvoiceReturnStatus;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWarehouseReturns extends ListRecords
{
    protected static string $resource = WarehouseReturnResource::class;

    public function getTitle(): string
    {
        return 'المرتجعات';
    }

    public function getSubheading(): ?string
    {
        return 'مرتجعات المخزن والشبكة — قبول / رفض الواردة المعلّقة';
    }

    public function getTabs(): array
    {
        $userId = auth()->id();

        return [
            'all' => Tab::make('الكل'),
            'needs_action' => Tab::make('تحتاج رد')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('status', InvoiceReturnStatus::PENDING)
                    ->where(function (Builder $inner) use ($userId) {
                        $inner->where('to_user_id', $userId)
                            ->orWhereHas('toUser', fn (Builder $u) => $u->where('role', 'wholesale_distributor'));
                    }))
                ->badge(fn () => WarehouseReturnResource::getEloquentQuery()
                    ->where('status', InvoiceReturnStatus::PENDING)
                    ->count()),
            'mine_incoming' => Tab::make('واردة للمخزن')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('to_user_id', $userId)),
            'pending' => Tab::make('معلّقة')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', InvoiceReturnStatus::PENDING)),
            'approved' => Tab::make('مقبولة')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereIn('status', InvoiceReturnStatus::settled())),
            'rejected' => Tab::make('مرفوضة')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', InvoiceReturnStatus::REJECTED)),
        ];
    }
}

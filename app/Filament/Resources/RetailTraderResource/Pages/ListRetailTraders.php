<?php

namespace App\Filament\Resources\RetailTraderResource\Pages;

use App\Filament\Resources\RetailTraderResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListRetailTraders extends ListRecords
{
    protected static string $resource = RetailTraderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('إضافة موزع قطاعي')
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    public function getTitle(): string
    {
        return 'تجّار القطاعي / التجزئة';
    }

    public function getTabs(): array
    {
        $base = User::query()->where('role', 'retail_trader');

        $user = auth()->user();
        if ($user?->isWholesaleDistributor()) {
            $wid = (int) $user->id;
            $base->where(function (Builder $q) use ($wid) {
                $q->where('parent_distributor_id', $wid)
                    ->orWhereHas('linkedWholesalers', fn (Builder $lq) => $lq->where('users.id', $wid));
            });
        }

        return [
            'all' => Tab::make('الكل')
                ->badge((clone $base)->count()),

            'pending' => Tab::make('طلبات التفعيل')
                ->icon('heroicon-o-bell-alert')
                ->badge((clone $base)->where('is_approved', false)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', false)),

            'approved' => Tab::make('مفعّلون')
                ->icon('heroicon-o-check-badge')
                ->badge((clone $base)->where('is_approved', true)->where('is_active', true)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', true)->where('is_active', true)),

            'inactive' => Tab::make('موقوفون')
                ->icon('heroicon-o-no-symbol')
                ->badge((clone $base)->where('is_active', false)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),

            'independent' => Tab::make('بدون جملة')
                ->icon('heroicon-o-user')
                ->badge((clone $base)->where(fn (Builder $q) => $q->where('is_independent', true)->orWhereNull('parent_distributor_id'))->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function (Builder $q) {
                    $q->where('is_independent', true)->orWhereNull('parent_distributor_id');
                })),
        ];
    }
}

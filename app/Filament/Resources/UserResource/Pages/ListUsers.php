<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('إضافة مستخدم'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('الكل')
                ->icon('heroicon-o-squares-2x2')
                ->badge(User::query()->count()),

            'plumbers' => Tab::make('السبّاكون')
                ->icon('heroicon-o-wrench-screwdriver')
                ->badge(User::query()->where('role', User::ROLE_PLUMBER)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', User::ROLE_PLUMBER)),

            'network' => Tab::make('شبكة التوزيع')
                ->icon('heroicon-o-building-storefront')
                ->badge(User::query()->whereIn('role', ['wholesale_distributor', 'retail_trader'])->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('role', ['wholesale_distributor', 'retail_trader'])),

            'vendors' => Tab::make('البائعون / المتاجر')
                ->icon('heroicon-o-shopping-bag')
                ->badge(User::query()->where('role', User::ROLE_VENDOR)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', User::ROLE_VENDOR)),

            'admins' => Tab::make('فريق الإدارة')
                ->icon('heroicon-o-shield-check')
                ->badge(User::query()->whereIn('role', ['super_admin', 'admin'])->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('role', ['super_admin', 'admin'])),
        ];
    }
}

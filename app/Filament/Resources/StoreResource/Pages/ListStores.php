<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use App\Models\User;
use App\Services\StoreApprovalService;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('إضافة متجر جديد')
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    public function getTitle(): string
    {
        return 'المتاجر — موزعو الجملة';
    }

    public function getTabs(): array
    {
        $base = User::query()->where('role', 'wholesale_distributor');

        return [
            'all' => Tab::make('الكل')
                ->badge((clone $base)->count()),

            'pending' => Tab::make('طلبات التفعيل')
                ->icon('heroicon-o-bell-alert')
                ->badge((clone $base)->where('is_approved', false)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', false)),

            'approved' => Tab::make('معتمدة')
                ->icon('heroicon-o-check-badge')
                ->badge((clone $base)->where('is_approved', true)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', true)),
        ];
    }
}

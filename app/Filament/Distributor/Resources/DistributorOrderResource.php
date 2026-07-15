<?php

namespace App\Filament\Distributor\Resources;

use App\Filament\Distributor\Resources\DistributorOrderResource\Pages;
use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DistributorOrderResource extends Resource
{
    protected static ?string $slug = 'orders';

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'الطلبيات';

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string { return 'طلبية'; }
    public static function getPluralModelLabel(): string { return 'الطلبيات'; }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $count = Order::query()->incomingFor($user)->where('status', 'placed')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->when($user, fn (Builder $q) => $q->forNetworkUser($user))
            ->with(['requester:id,name,brand_name', 'supplier:id,name,brand_name']);
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public static function form(Form $form): Form
    {
        return OrderResource::form($form);
    }

    public static function table(Table $table): Table
    {
        return OrderResource::table($table);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return OrderResource::infolist($infolist);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDistributorOrders::route('/'),
            'create' => Pages\CreateDistributorOrder::route('/create'),
            'view' => Pages\ViewDistributorOrder::route('/{record}'),
        ];
    }
}

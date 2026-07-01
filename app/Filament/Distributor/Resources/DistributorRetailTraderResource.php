<?php

namespace App\Filament\Distributor\Resources;

use App\Filament\Distributor\Resources\DistributorRetailTraderResource\Pages;
use App\Filament\Resources\RetailTraderResource;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DistributorRetailTraderResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'تجار القطاعي';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'retail-traders';

    public static function getNavigationGroup(): ?string
    {
        return 'شبكتي';
    }

    public static function getModelLabel(): string
    {
        return 'تاجر قطاعي';
    }

    public static function getPluralModelLabel(): string
    {
        return 'تجار القطاعي';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', 'retail_trader')
            ->where('parent_distributor_id', auth()->id())
            ->with(['country', 'city'])
            ->withCount('plumbers');
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canEdit($record): bool
    {
        return (int) $record->parent_distributor_id === (int) auth()->id();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return RetailTraderResource::form($form);
    }

    public static function table(Table $table): Table
    {
        return RetailTraderResource::table($table);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return RetailTraderResource::infolist($infolist);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDistributorRetailTraders::route('/'),
            'create' => Pages\CreateDistributorRetailTrader::route('/create'),
            'view' => Pages\ViewDistributorRetailTrader::route('/{record}'),
            'edit' => Pages\EditDistributorRetailTrader::route('/{record}/edit'),
        ];
    }
}

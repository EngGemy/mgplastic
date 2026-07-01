<?php

namespace App\Filament\Trader\Resources;

use App\Filament\RelationManagers\SocialLinksRelationManager;
use App\Filament\RelationManagers\StoreMediaRelationManager;
use App\Filament\Resources\MyStoreResource;
use App\Filament\Trader\Resources\TraderMyStoreResource\Pages;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TraderMyStoreResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'my-store';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return 'متجري والكتالوج';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'متجري';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereKey(auth()->id());
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canView(?Model $record): bool
    {
        return (int) $record?->getKey() === (int) auth()->id();
    }

    public static function canEdit(?Model $record): bool
    {
        return static::canView($record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return MyStoreResource::form($form);
    }

    public static function getRelations(): array
    {
        return [
            StoreMediaRelationManager::class,
            SocialLinksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\EditTraderMyStore::route('/'),
        ];
    }
}

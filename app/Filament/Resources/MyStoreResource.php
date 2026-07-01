<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasStoreLocationForm;
use App\Filament\RelationManagers\SocialLinksRelationManager;
use App\Filament\RelationManagers\StoreMediaRelationManager;
use App\Filament\Resources\MyStoreResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MyStoreResource extends Resource
{
    use HasStoreLocationForm;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'my-store';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = -1;

    public static function getNavigationLabel(): string
    {
        return 'متجري والكتالوج';
    }

    public static function getModelLabel(): string
    {
        return 'متجري';
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->whereKey($user?->id)
            ->whereIn('role', ['wholesale_distributor', 'retail_trader']);
    }

    public static function canAccessNetworkStore(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user && ($user->isWholesaleDistributor() || $user->isRetailTrader());
    }

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canView(?Model $record): bool
    {
        return static::canAccessNetworkStore() && (int) $record?->getKey() === (int) auth()->id();
    }

    public static function canEdit(?Model $record): bool
    {
        return static::canView($record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(?Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('my_store_tabs')
                ->tabs([
                    Forms\Components\Tabs\Tab::make('البيانات الأساسية')
                        ->icon('heroicon-o-building-storefront')
                        ->schema([
                            Forms\Components\Section::make('هوية المتجر')
                                ->schema([
                                    ...self::storeIdentityFields(withPassword: false, nameLabel: 'اسم المتجر'),
                                ])
                                ->columns(3),
                        ]),

                    Forms\Components\Tabs\Tab::make('الموقع والعنوان')
                        ->icon('heroicon-o-map-pin')
                        ->schema([
                            Forms\Components\Section::make('الموقع — OpenStreetMap')
                                ->schema(self::storeLocationFields())
                                ->columns(2),
                        ]),

                    Forms\Components\Tabs\Tab::make('نبذة المتجر')
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Forms\Components\Section::make('وصف المتجر للعملاء')
                                ->description('يظهر في التطبيق وصفحة المتجر العامة')
                                ->schema(self::storeCatalogFields()),
                        ]),
                ])
                ->columnSpanFull()
                ->persistTabInQueryString(),
        ]);
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
            'index' => Pages\EditMyStore::route('/'),
        ];
    }
}

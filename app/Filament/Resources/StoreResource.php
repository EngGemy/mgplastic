<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Concerns\HasStoreLocationForm;
use App\Filament\Concerns\HasNetworkInfolist;
use App\Filament\Resources\StoreResource\Pages;
use App\Filament\Resources\StoreResource\RelationManagers;
use App\Models\SystemLabel;
use App\Models\User;
use App\Services\StoreApprovalService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StoreResource extends Resource
{
    use AdminOnlyResource;
    use HasStoreLocationForm;
    use HasNetworkInfolist;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $slug = 'stores';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'الشبكة التجارية';
    }

    public static function getNavigationLabel(): string
    {
        return SystemLabel::get('stores', 'المتاجر');
    }

    public static function getModelLabel(): string
    {
        return 'متجر';
    }

    public static function getPluralModelLabel(): string
    {
        return SystemLabel::get('stores', 'المتاجر');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = StoreApprovalService::pendingWholesaleCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'طلبات تفعيل بانتظار الموافقة';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', 'wholesale_distributor')
            ->withCount('retailTraders')
            ->with(['country', 'city', 'storeMedia', 'socialLinks']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('🏪 بيانات المتجر (موزع الجملة)')
                ->description('المتجر الرئيسي في شبكة التوزيع')
                ->schema([
                    ...self::storeIdentityFields(withPassword: true),
                ])->columns(3),

            Forms\Components\Section::make('📍 الموقع — OpenStreetMap')
                ->description('حدّد موقع المتجر على الخريطة أو أدخل الإحداثيات')
                ->schema(self::storeLocationFields())
                ->columns(2),

            Forms\Components\Section::make('📝 تفاصيل إضافية')
                ->schema(self::storeCatalogFields()),

            Forms\Components\Section::make('⚙️ الحالة')
                ->schema(self::storeStatusFields())
                ->columns(3),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            self::networkProfileHeaderEntry(),
            self::networkContactSection(),
            self::networkCatalogSection(),
            self::networkSocialSection(),
            self::networkMapSection(),
            self::networkStatusSection(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->recordClasses(fn (User $record) => ! $record->is_approved ? 'mg-pending-store' : null)
            ->columns([
                Tables\Columns\ImageColumn::make('profile_photo')
                    ->label('')
                    ->circular()
                    ->size(40),

                Tables\Columns\TextColumn::make('name')
                    ->label('المتجر')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $r) => $r->phone),

                Tables\Columns\TextColumn::make('brand_name')
                    ->label('العلامة')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('city.name_ar')
                    ->label('المدينة')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('retail_traders_count')
                    ->label('قطاعي')
                    ->badge()
                    ->color('info')
                    ->suffix(' موزع'),

                Tables\Columns\IconColumn::make('has_map')
                    ->label('خريطة')
                    ->boolean()
                    ->getStateUsing(fn (User $r) => $r->hasMapLocation()),

                Tables\Columns\IconColumn::make('is_approved')
                    ->label('معتمد')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ التسجيل')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_approved')
                    ->label('الاعتماد')
                    ->placeholder('الكل')
                    ->trueLabel('معتمد')
                    ->falseLabel('بانتظار التفعيل'),
                Tables\Filters\TernaryFilter::make('is_active')->label('نشط'),
                Tables\Filters\TernaryFilter::make('has_location')
                    ->label('موقع محدد')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('latitude')->whereNotNull('longitude'),
                        false: fn ($q) => $q->where(fn ($qq) => $qq->whereNull('latitude')->orWhereNull('longitude')),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('تفعيل المتجر')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (User $record) => ! $record->is_approved)
                    ->requiresConfirmation()
                    ->modalHeading('تفعيل المتجر')
                    ->modalDescription(fn (User $record) => "هل تريد اعتماد وتفعيل متجر «{$record->name}»؟ سيظهر في الشبكة ويُبلَّغ صاحبه.")
                    ->action(function (User $record) {
                        app(StoreApprovalService::class)->approve($record, auth()->user());
                        Notification::make()->success()->title('تم تفعيل المتجر')->send();
                    }),

                Tables\Actions\Action::make('add_retail')
                    ->label('إضافة موزع قطاعي')
                    ->icon('heroicon-o-plus')
                    ->color('success')
                    ->visible(fn (User $record) => (bool) $record->is_approved)
                    ->url(fn (User $record) => \App\Filament\Resources\RetailTraderResource::getUrl('create').'?store='.$record->id),

                Tables\Actions\ViewAction::make()->label('عرض'),
                Tables\Actions\EditAction::make()->label('تعديل'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulkApprove')
                        ->label('تفعيل المحدد')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $service = app(StoreApprovalService::class);
                            $records->each(fn (User $store) => $service->approve($store, auth()->user()));
                            Notification::make()->success()->title('تم تفعيل المتاجر المحددة')->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('لا توجد متاجر')
            ->emptyStateDescription('أضف أول متجر (موزع جملة) لبدء شبكة التوزيع')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\WholesaleInvoicesRelationManager::class,
            RelationManagers\RetailTradersRelationManager::class,
            RelationManagers\NetworkPlumbersRelationManager::class,
            \App\Filament\RelationManagers\StoreMediaRelationManager::class,
            \App\Filament\RelationManagers\SocialLinksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStores::route('/'),
            'create' => Pages\CreateStore::route('/create'),
            'view' => Pages\ViewStore::route('/{record}'),
            'edit' => Pages\EditStore::route('/{record}/edit'),
        ];
    }
}

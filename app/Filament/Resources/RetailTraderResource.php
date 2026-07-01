<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasStoreLocationForm;
use App\Filament\Concerns\HasNetworkInfolist;
use App\Filament\Resources\RetailTraderResource\Pages;
use App\Filament\Resources\RetailTraderResource\RelationManagers;
use App\Models\SystemLabel;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RetailTraderResource extends Resource
{
    use HasStoreLocationForm;
    use HasNetworkInfolist;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $slug = 'retail-traders';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'الشبكة التجارية';
    }

    public static function getNavigationLabel(): string
    {
        return SystemLabel::get('retail_trader', 'موزعون قطاعيون');
    }

    public static function getModelLabel(): string
    {
        return 'موزع قطاعي';
    }

    public static function getPluralModelLabel(): string
    {
        return SystemLabel::get('retail_trader', 'موزعون قطاعيون');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where('role', 'retail_trader')
            ->with(['parentDistributor', 'country', 'city', 'storeMedia', 'socialLinks'])
            ->withCount('plumbers');

        $user = auth()->user();

        if ($user?->isWholesaleDistributor()) {
            $query->where('parent_distributor_id', $user->id);
        }

        return $query;
    }

    /** نموذج التعديل — تبويبات */
    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Tabs::make('retail_tabs')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('البيانات')
                            ->icon('heroicon-o-user')
                            ->schema([
                                Forms\Components\Grid::make(3)->schema([
                                    ...self::storeIdentityFields(
                                        withPassword: false,
                                        nameLabel: 'اسم الموزع / المتجر',
                                        compactPhoto: true,
                                    ),
                                ]),
                                ...self::retailAffiliationFields(),
                                ...self::brandCompanyFields(),
                            ]),

                        Forms\Components\Tabs\Tab::make('الموقع')
                            ->icon('heroicon-o-map-pin')
                            ->schema(self::storeLocationFields(hideCoordinates: true)),

                        Forms\Components\Tabs\Tab::make('إضافي')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                ...self::storeDetailsFields(),
                                Forms\Components\TextInput::make('password')
                                    ->label('كلمة مرور جديدة')
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->minLength(6),
                                ...self::storeStatusFields(),
                            ]),
                    ])
                    ->columnSpanFull()
                    ->persistTabInQueryString(),
            ]);
    }

    /** نموذج الإنشاء — معالج خطوات */
    public static function createWizardForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Wizard::make([
                Forms\Components\Wizard\Step::make('البيانات الأساسية')
                    ->description('الاسم والتواصل')
                    ->icon('heroicon-o-identification')
                    ->schema([
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\FileUpload::make('profile_photo')
                                ->label('صورة')
                                ->disk('public')
                                ->directory('profile_photos')
                                ->image()
                                ->avatar()
                                ->imageEditor()
                                ->columnSpan(1),

                            Forms\Components\Group::make([
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم الموزع / المتجر')
                                    ->placeholder('مثال: متجر الأندلس')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('phone')
                                    ->label('رقم الهاتف')
                                    ->placeholder('09XXXXXXXX')
                                    ->tel()
                                    ->required(),

                                Forms\Components\TextInput::make('email')
                                    ->label('البريد')
                                    ->placeholder('اختياري')
                                    ->email(),
                            ])->columnSpan(2),
                        ]),

                        Forms\Components\TextInput::make('password')
                            ->label('كلمة المرور')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(6)
                            ->helperText('سيستخدمها الموزع لتسجيل الدخول للتطبيق')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Wizard\Step::make('الانتماء')
                    ->description('تابع لمتجر أو منفرد')
                    ->icon('heroicon-o-link')
                    ->schema(
                        auth()->user()?->isWholesaleDistributor()
                            ? self::retailAffiliationLockedToWholesaler((int) auth()->id())
                            : [
                                Forms\Components\Placeholder::make('affiliation_help')
                                    ->content('حدّد هل هذا الموزع تابعاً لمتجر جملة موجود، أم يعمل بشكل مستقل.')
                                    ->columnSpanFull(),
                                ...self::retailAffiliationFields(),
                            ]
                    ),

                Forms\Components\Wizard\Step::make('الموقع')
                    ->description('العنوان والخريطة')
                    ->icon('heroicon-o-map-pin')
                    ->schema([
                        Forms\Components\Placeholder::make('map_help')
                            ->content('📍 انقر على الخريطة لتحديد موقع المتجر — OpenStreetMap')
                            ->columnSpanFull(),

                        ...self::storeLocationFields(hideCoordinates: true),

                        ...self::storeCatalogFields(),
                    ]),

                Forms\Components\Wizard\Step::make('تأكيد')
                    ->description('مراجعة وحفظ')
                    ->icon('heroicon-o-check-circle')
                    ->schema([
                        Forms\Components\Placeholder::make('summary')
                            ->label('جاهز للحفظ')
                            ->content('اضغط «إنشاء» لحفظ الموزع القطاعي. الحساب سيكون معتمداً ونشطاً افتراضياً.')
                            ->columnSpanFull(),

                        Forms\Components\Hidden::make('is_approved')->default(true),
                        Forms\Components\Hidden::make('is_active')->default(true),
                        Forms\Components\Hidden::make('is_phone_verified')->default(true),
                    ]),
            ])
                ->skippable(false)
                ->nextAction(fn (\Filament\Forms\Components\Actions\Action $action) => $action->label('التالي ←'))
                ->previousAction(fn (\Filament\Forms\Components\Actions\Action $action) => $action->label('→ السابق'))
                ->columnSpanFull(),
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
            self::pointsBalanceSection(),
            self::receivedDistributionsSection(),
        ]);
    }

    protected static function pointsBalanceSection(): Infolists\Components\Section
    {
        return Infolists\Components\Section::make('رصيد النقاط')
            ->icon('heroicon-o-star')
            ->schema([
                Infolists\Components\Grid::make(3)->schema([
                    Infolists\Components\TextEntry::make('wallet_points')
                        ->label('الرصيد الحالي')
                        ->getStateUsing(function ($record) {
                            $wallet = \App\Models\WalletAccount::where('owner_id', $record->id)
                                ->where('currency', 'LYD')
                                ->first();

                            return number_format($wallet?->balance_points ?? 0).' نقطة';
                        })
                        ->size(\Filament\Infolists\Components\TextEntry\TextEntrySize::Large)
                        ->weight(\Filament\Support\Enums\FontWeight::Bold)
                        ->color('success')
                        ->icon('heroicon-o-star'),

                    Infolists\Components\TextEntry::make('total_points_received')
                        ->label('إجمالي ما استُلم')
                        ->getStateUsing(function ($record) {
                            $total = \App\Models\WalletTransaction::query()
                                ->whereHas('wallet', fn ($q) => $q->where('owner_id', $record->id))
                                ->where('type', 'credit')
                                ->sum('points_delta');

                            return number_format((int) $total).' نقطة';
                        })
                        ->icon('heroicon-o-arrow-trending-up')
                        ->color('primary'),

                    Infolists\Components\TextEntry::make('distributions_count')
                        ->label('توزيعات مستلمة')
                        ->getStateUsing(function ($record) {
                            return \App\Models\InvoiceDistribution::where('to_user_id', $record->id)
                                ->whereIn('status', ['confirmed', 'points_awarded'])
                                ->count().' توزيع';
                        })
                        ->icon('heroicon-o-arrows-pointing-out')
                        ->color('info'),
                ]),
            ]);
    }

    protected static function receivedDistributionsSection(): Infolists\Components\Section
    {
        return Infolists\Components\Section::make('تفاصيل النقاط المستلمة — بالمنتجات والفواتير')
            ->icon('heroicon-o-document-text')
            ->collapsible()
            ->collapsed(false)
            ->schema([
                Infolists\Components\ViewEntry::make('received_distributions_table')
                    ->view('filament.infolists.retail-trader-distributions')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('parentDistributor.name')
                    ->label('المتجر')
                    ->default('— منفرد —')
                    ->badge()
                    ->color(fn ($state) => $state ? 'primary' : 'gray'),

                Tables\Columns\TextColumn::make('phone')->label('الهاتف'),

                Tables\Columns\TextColumn::make('plumbers_count')
                    ->label('سباكين')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\IconColumn::make('is_independent')
                    ->label('منفرد')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_independent')->label('منفرد'),
                Tables\Filters\SelectFilter::make('parent_distributor_id')
                    ->label('المتجر')
                    ->options(fn () => User::where('role', 'wholesale_distributor')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('عرض'),
                Tables\Actions\EditAction::make()->label('تعديل'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PlumbersRelationManager::class,
            \App\Filament\RelationManagers\StoreMediaRelationManager::class,
            \App\Filament\RelationManagers\SocialLinksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRetailTraders::route('/'),
            'create' => Pages\CreateRetailTrader::route('/create'),
            'view' => Pages\ViewRetailTrader::route('/{record}'),
            'edit' => Pages\EditRetailTrader::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin', 'wholesale_distributor'], true);
    }

    public static function canCreate(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin', 'wholesale_distributor'], true);
    }

    public static function canView($record): bool
    {
        if (! $record instanceof User) {
            return false;
        }

        if (in_array(auth()->user()?->role, ['super_admin', 'admin'], true)) {
            return true;
        }

        return auth()->user()?->isWholesaleDistributor()
            && (int) $record->parent_distributor_id === (int) auth()->id();
    }

    public static function canEdit($record): bool
    {
        return static::canView($record);
    }
}

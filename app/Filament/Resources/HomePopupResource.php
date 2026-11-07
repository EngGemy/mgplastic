<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\HomePopupResource\Pages;
use App\Models\Event;
use App\Models\HomePopup;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductPromotion;
use App\Models\User;
use App\Services\HomeMobileLayoutService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HomePopupResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = HomePopup::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 42;

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'إعلانات الرئيسية';
    }

    public static function getModelLabel(): string
    {
        return 'إعلان منبثق';
    }

    public static function getPluralModelLabel(): string
    {
        return 'إعلانات الرئيسية';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('محتوى الإعلان')
                            ->description('الصورة والعنوان اللي تظهر في الـ popup أول ما يفتح المستخدم الرئيسية.')
                            ->icon('heroicon-o-megaphone')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('العنوان')
                                    ->placeholder('مثال: سباك مميز هذا الأسبوع')
                                    ->maxLength(200)
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('subtitle')
                                    ->label('وصف قصير')
                                    ->placeholder('سطر تحت العنوان (اختياري)')
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                Forms\Components\FileUpload::make('image_path')
                                    ->label('صورة الإعلان')
                                    ->disk('public')
                                    ->directory('home-popups')
                                    ->image()
                                    ->imageEditor()
                                    ->imageEditorAspectRatios(['4:5', '3:4', '1:1', null])
                                    ->imagePreviewHeight('260')
                                    ->openable()
                                    ->downloadable()
                                    ->maxSize(5120)
                                    ->required()
                                    ->helperText('يفضّل صورة عمودية تقريباً 4:5 — حتى 5MB')
                                    ->columnSpanFull(),

                                Forms\Components\ToggleButtons::make('kind')
                                    ->label('نوع الإعلان')
                                    ->options([
                                        'promo' => 'عرض / دعاية',
                                        'announcement' => 'تنويه',
                                        'event' => 'فعالية',
                                        'feature' => 'سباك / محل مميز',
                                        'auth' => 'تسجيل / دخول',
                                    ])
                                    ->inline()
                                    ->required()
                                    ->default('promo')
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('الزر والوجهة')
                            ->description('نص الزر (cta.text) ووجهة الضغط (cta.link).')
                            ->icon('heroicon-o-cursor-arrow-rays')
                            ->schema([
                                Forms\Components\TextInput::make('cta_text')
                                    ->label('نص الزر')
                                    ->placeholder('مثال: عرض السباك · سجّل الآن')
                                    ->required()
                                    ->maxLength(80)
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('link_type')
                                    ->label('عند الضغط يفتح')
                                    ->options([
                                        'home' => 'الرئيسية',
                                        'auth_login' => 'تسجيل الدخول',
                                        'auth_register' => 'شاشة التسجيل (اختيار نوع)',
                                        'auth_register_plumber' => 'تسجيل سباك',
                                        'auth_register_retail' => 'تسجيل قطاعي',
                                        'plumber' => 'صفحة سباك',
                                        'shop' => 'صفحة محل',
                                        'event' => 'فعالية',
                                        'product' => 'منتج',
                                        'product_collection' => 'مجموعة منتجات',
                                        'product_promotion' => 'إعلان MG',
                                        'catalog' => 'الكتالوج',
                                        'wallet' => 'المحفظة',
                                        'points' => 'النقاط',
                                        'pending_receipts' => 'بضاعة بانتظار الاستلام',
                                        'support' => 'الدعم',
                                        'deep_link' => 'مسار deep link حر',
                                        'url' => 'رابط خارجي https',
                                    ])
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->default('home')
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('link_path')
                                    ->label('مسار الـ deep link')
                                    ->placeholder('مثال: auth/login أو list/shops أو trader-orders')
                                    ->helperText('بدون mgplastic:// — التطبيق يبني الرابط تلقائياً')
                                    ->required(fn (Get $get) => $get('link_type') === 'deep_link')
                                    ->visible(fn (Get $get) => $get('link_type') === 'deep_link')
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('link_url')
                                    ->label('الرابط الخارجي')
                                    ->placeholder('https://…')
                                    ->url()
                                    ->prefixIcon('heroicon-o-link')
                                    ->required(fn (Get $get) => $get('link_type') === 'url')
                                    ->visible(fn (Get $get) => $get('link_type') === 'url')
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('link_id')
                                    ->label(fn (Get $get) => match ($get('link_type')) {
                                        'plumber' => 'السباك',
                                        'shop' => 'المحل',
                                        'event' => 'الفعالية',
                                        'product_collection' => 'المجموعة',
                                        'product_promotion' => 'الإعلان',
                                        'product' => 'المنتج',
                                        default => 'الهدف',
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required(fn (Get $get) => in_array($get('link_type'), [
                                        'plumber',
                                        'shop',
                                        'event',
                                        'product',
                                        'product_collection',
                                        'product_promotion',
                                    ], true))
                                    ->options(function (Get $get) {
                                        return match ($get('link_type')) {
                                            'plumber' => User::query()
                                                ->where('role', 'plumber')
                                                ->where('is_active', true)
                                                ->orderBy('name')
                                                ->limit(500)
                                                ->get()
                                                ->mapWithKeys(fn (User $u) => [
                                                    $u->id => trim(($u->name ?: 'سباك').' #'.$u->id),
                                                ]),
                                            'shop' => User::query()
                                                ->whereIn('role', ['retail_trader', 'wholesale_distributor'])
                                                ->where('is_active', true)
                                                ->orderBy('brand_name')
                                                ->orderBy('name')
                                                ->limit(500)
                                                ->get()
                                                ->mapWithKeys(fn (User $u) => [
                                                    $u->id => trim(($u->brand_name ?: $u->name ?: 'محل').' #'.$u->id),
                                                ]),
                                            'event' => Event::query()
                                                ->with('translations')
                                                ->orderByDesc('id')
                                                ->limit(200)
                                                ->get()
                                                ->mapWithKeys(fn (Event $e) => [
                                                    $e->id => (string) (localized_name($e, 'title', "فعالية #{$e->id}")),
                                                ]),
                                            'product_collection' => ProductCollection::query()
                                                ->where('is_active', true)
                                                ->orderBy('sort_order')
                                                ->orderBy('title')
                                                ->pluck('title', 'id'),
                                            'product_promotion' => ProductPromotion::query()
                                                ->where('is_active', true)
                                                ->orderBy('sort_order')
                                                ->orderBy('title')
                                                ->pluck('title', 'id'),
                                            'product' => Product::query()
                                                ->with('translations')
                                                ->orderBy('id')
                                                ->limit(500)
                                                ->get()
                                                ->mapWithKeys(fn (Product $p) => [
                                                    $p->id => localized_name($p, 'name', "منتج #{$p->id}"),
                                                ]),
                                            default => [],
                                        };
                                    })
                                    ->visible(fn (Get $get) => in_array($get('link_type'), [
                                        'plumber',
                                        'shop',
                                        'event',
                                        'product',
                                        'product_collection',
                                        'product_promotion',
                                    ], true))
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('الجمهور والجدولة')
                            ->icon('heroicon-o-user-group')
                            ->schema([
                                Forms\Components\CheckboxList::make('target_roles')
                                    ->label('يظهر لـ')
                                    ->options([
                                        'guest' => 'زائر (بدون توكن)',
                                        'plumber' => 'سباك',
                                        'retail_trader' => 'تاجر قطاعي',
                                        'wholesale_distributor' => 'موزع جملة',
                                    ])
                                    ->columns(1)
                                    ->required()
                                    ->default(['guest'])
                                    ->helperText('لازم تختار دور واحد على الأقل'),

                                Forms\Components\ToggleButtons::make('frequency')
                                    ->label('تكرار العرض')
                                    ->options([
                                        'once' => 'مرة واحدة (بعد الإغلاق)',
                                        'once_per_day' => 'مرة يومياً',
                                        'every_open' => 'كل فتح للرئيسية',
                                    ])
                                    ->inline()
                                    ->required()
                                    ->default('once')
                                    ->columnSpanFull(),

                                Forms\Components\Toggle::make('dismissible')
                                    ->label('يمكن إغلاقه بـ ✕')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\DateTimePicker::make('starts_at')
                                    ->label('يبدأ من')
                                    ->seconds(false)
                                    ->native(false),

                                Forms\Components\DateTimePicker::make('ends_at')
                                    ->label('ينتهي في')
                                    ->seconds(false)
                                    ->native(false),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('ظاهر في التطبيق')
                                    ->helperText('عطّله لإخفاء الإعلان دون حذفه.')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\TextInput::make('sort_order')
                                    ->label('الأولوية')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->helperText('الأعلى يظهر أولاً لو فيه أكتر من إعلان لنفس الدور'),
                            ]),

                        Forms\Components\Section::make('للتطبيق')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->schema([
                                Forms\Components\Placeholder::make('api_hint')
                                    ->label('')
                                    ->content('GET /api/v1/mobile/home/popup — Bearer اختياري · ?role=guest'),
                                Forms\Components\Placeholder::make('roles_hint')
                                    ->label('')
                                    ->content('الأدوار: '.implode(' · ', HomeMobileLayoutService::ROLES)),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('صورة')
                    ->disk('public')
                    ->square()
                    ->size(72),

                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->placeholder('—')
                    ->searchable()
                    ->weight('bold')
                    ->wrap()
                    ->limit(40),

                Tables\Columns\TextColumn::make('cta_text')
                    ->label('الزر')
                    ->limit(24),

                Tables\Columns\TextColumn::make('kind')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'promo' => 'عرض',
                        'announcement' => 'تنويه',
                        'event' => 'فعالية',
                        'feature' => 'مميز',
                        'auth' => 'تسجيل',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('target_roles')
                    ->label('الجمهور')
                    ->formatStateUsing(function ($state) {
                        $roles = is_array($state) ? $state : [];
                        $labels = [
                            'guest' => 'زائر',
                            'plumber' => 'سباك',
                            'retail_trader' => 'قطاعي',
                            'wholesale_distributor' => 'جملة',
                        ];

                        return collect($roles)->map(fn ($r) => $labels[$r] ?? $r)->implode(' · ') ?: '—';
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('أولوية')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('ظاهر')
                    ->boolean(),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('ينتهي')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('sort_order', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label('النوع')
                    ->options([
                        'promo' => 'عرض',
                        'announcement' => 'تنويه',
                        'event' => 'فعالية',
                        'feature' => 'مميز',
                        'auth' => 'تسجيل',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('ظاهر في التطبيق'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('لا توجد إعلانات بعد')
            ->emptyStateDescription('أضف إعلاناً منبثقاً ليظهر عند فتح الرئيسية في التطبيق.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomePopups::route('/'),
            'create' => Pages\CreateHomePopup::route('/create'),
            'edit' => Pages\EditHomePopup::route('/{record}/edit'),
        ];
    }
}

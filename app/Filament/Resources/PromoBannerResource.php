<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\PromoBannerResource\Pages;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductPromotion;
use App\Models\PromoBanner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PromoBannerResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = PromoBanner::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 43;

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'بانرات دعائية';
    }

    public static function getModelLabel(): string
    {
        return 'بانر دعائي';
    }

    public static function getPluralModelLabel(): string
    {
        return 'بانرات دعائية';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('محتوى البانر')
                            ->description('النص والصورة اللي تظهر في التطبيق بين السباكين والمتاجر.')
                            ->icon('heroicon-o-megaphone')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('العنوان')
                                    ->placeholder('مثال: عروض أنابيب MG هذا الأسبوع')
                                    ->required()
                                    ->maxLength(200)
                                    ->autofocus()
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('subtitle')
                                    ->label('عنوان فرعي')
                                    ->placeholder('سطر قصير تحت العنوان (اختياري)')
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                Forms\Components\FileUpload::make('image_path')
                                    ->label('صورة البانر')
                                    ->disk('public')
                                    ->directory('promo-banners')
                                    ->image()
                                    ->imageEditor()
                                    ->imageEditorAspectRatios(['16:9', '21:9', '4:3', null])
                                    ->imagePreviewHeight('220')
                                    ->openable()
                                    ->downloadable()
                                    ->maxSize(5120)
                                    ->required()
                                    ->helperText('يفضّل صورة أفقية عريضة (16:9) — حتى 5MB')
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('أين يظهر؟ وماذا يفتح؟')
                            ->description('حدد السكشن في التطبيق، ثم وجهة الضغط على البانر.')
                            ->icon('heroicon-o-cursor-arrow-rays')
                            ->schema([
                                Forms\Components\ToggleButtons::make('section')
                                    ->label('مكان العرض')
                                    ->options([
                                        PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES => 'بين السباكين والمتاجر',
                                    ])
                                    ->icons([
                                        PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES => 'heroicon-o-squares-2x2',
                                    ])
                                    ->colors([
                                        PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES => 'primary',
                                    ])
                                    ->inline()
                                    ->required()
                                    ->default(PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES)
                                    ->columnSpanFull(),

                                Forms\Components\ToggleButtons::make('link_type')
                                    ->label('عند الضغط يفتح')
                                    ->options([
                                        'none' => 'بدون رابط',
                                        'url' => 'رابط خارجي',
                                        'product_collection' => 'مجموعة منتجات',
                                        'product_promotion' => 'إعلان MG',
                                        'product' => 'منتج',
                                    ])
                                    ->icons([
                                        'none' => 'heroicon-o-minus-circle',
                                        'url' => 'heroicon-o-globe-alt',
                                        'product_collection' => 'heroicon-o-rectangle-group',
                                        'product_promotion' => 'heroicon-o-megaphone',
                                        'product' => 'heroicon-o-cube',
                                    ])
                                    ->colors([
                                        'none' => 'gray',
                                        'url' => 'info',
                                        'product_collection' => 'success',
                                        'product_promotion' => 'warning',
                                        'product' => 'primary',
                                    ])
                                    ->inline()
                                    ->required()
                                    ->live()
                                    ->default('none')
                                    ->columnSpanFull(),

                                Forms\Components\Placeholder::make('link_hint')
                                    ->label('')
                                    ->content(fn (Get $get) => match ($get('link_type')) {
                                        'url' => 'ضع رابطاً كاملاً يبدأ بـ https://',
                                        'product_collection' => 'اختر مجموعة من «مجموعات المنتجات» ليفتحها التطبيق.',
                                        'product_promotion' => 'اختر إعلاناً من «دعاية منتجات MG».',
                                        'product' => 'ابحث عن منتج من كتالوج MG.',
                                        default => 'البانر للعرض فقط — بدون انتقال عند الضغط.',
                                    })
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
                                        'product_collection' => 'المجموعة',
                                        'product_promotion' => 'الإعلان',
                                        'product' => 'المنتج',
                                        default => 'الهدف',
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required(fn (Get $get) => in_array($get('link_type'), [
                                        'product_collection',
                                        'product_promotion',
                                        'product',
                                    ], true))
                                    ->options(function (Get $get) {
                                        return match ($get('link_type')) {
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
                                        'product_collection',
                                        'product_promotion',
                                        'product',
                                    ], true))
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('النشر')
                            ->icon('heroicon-o-rocket-launch')
                            ->schema([
                                Forms\Components\Toggle::make('is_active')
                                    ->label('ظاهر في التطبيق')
                                    ->helperText('عطّله لإخفاء البانر دون حذفه.')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\TextInput::make('sort_order')
                                    ->label('ترتيب الظهور')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->helperText('الأقل يظهر أولاً'),
                            ]),

                        Forms\Components\Section::make('للتطبيق')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->schema([
                                Forms\Components\Placeholder::make('api_hint')
                                    ->label('')
                                    ->content('GET /api/v1/mobile/promo-banners?section=between_plumbers_stores'),
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
                    ->searchable()
                    ->weight('bold')
                    ->wrap()
                    ->limit(45),

                Tables\Columns\TextColumn::make('section')
                    ->label('السكنشن')
                    ->formatStateUsing(fn (string $state) => $state === PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES
                        ? 'بين السباكين والمتاجر'
                        : $state)
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('link_type')
                    ->label('الرابط')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'url' => 'info',
                        'product_collection' => 'success',
                        'product_promotion' => 'warning',
                        'product' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'url' => 'رابط خارجي',
                        'product_collection' => 'مجموعة',
                        'product_promotion' => 'إعلان',
                        'product' => 'منتج',
                        default => 'بدون',
                    }),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('ترتيب')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('ظاهر')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('link_type')
                    ->label('نوع الرابط')
                    ->options([
                        'none' => 'بدون',
                        'url' => 'رابط خارجي',
                        'product_collection' => 'مجموعة',
                        'product_promotion' => 'إعلان',
                        'product' => 'منتج',
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
            ->emptyStateHeading('لا توجد بانرات بعد')
            ->emptyStateDescription('أضف بانر دعائي ليظهر بين السباكين والمتاجر في التطبيق.')
            ->emptyStateIcon('heroicon-o-photo');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoBanners::route('/'),
            'create' => Pages\CreatePromoBanner::route('/create'),
            'edit' => Pages\EditPromoBanner::route('/{record}/edit'),
        ];
    }
}

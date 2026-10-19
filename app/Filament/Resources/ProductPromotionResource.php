<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\ProductPromotionResource\Pages;
use App\Models\Product;
use App\Models\ProductPromotion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductPromotionResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = ProductPromotion::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'دعاية منتجات MG';
    }

    public static function getModelLabel(): string
    {
        return 'إعلان منتج';
    }

    public static function getPluralModelLabel(): string
    {
        return 'دعاية منتجات MG';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('نص الإعلان')
                            ->description('العنوان والوصف اللي يظهروا للمستخدم في التطبيق.')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('العنوان')
                                    ->placeholder('مثال: مقارنة أنابيب MG مع الأنواع التقليدية')
                                    ->required()
                                    ->maxLength(200)
                                    ->autofocus()
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('subtitle')
                                    ->label('عنوان فرعي')
                                    ->placeholder('سطر قصير تحت العنوان')
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('body')
                                    ->label('الوصف / نص المقارنة')
                                    ->placeholder('اكتب مزايا المنتج، نقاط المقارنة، أو رسالة الدعاية…')
                                    ->rows(5)
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('الوسائط')
                            ->description('اختر نوع المحتوى ثم ارفع الملفات المناسبة.')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\ToggleButtons::make('media_type')
                                    ->label('نوع المحتوى')
                                    ->options([
                                        'image' => 'صورة',
                                        'video' => 'فيديو',
                                        'comparison' => 'مقارنة',
                                    ])
                                    ->icons([
                                        'image' => 'heroicon-o-photo',
                                        'video' => 'heroicon-o-play-circle',
                                        'comparison' => 'heroicon-o-arrows-right-left',
                                    ])
                                    ->colors([
                                        'image' => 'primary',
                                        'video' => 'danger',
                                        'comparison' => 'warning',
                                    ])
                                    ->inline()
                                    ->required()
                                    ->live()
                                    ->default('image')
                                    ->columnSpanFull(),

                                Forms\Components\Placeholder::make('media_hint')
                                    ->label('')
                                    ->content(fn (Get $get) => match ($get('media_type')) {
                                        'video' => 'ارفع صورة غلاف للفيديو ثم ضع رابط يوتيوب / فيميو / ملف الفيديو.',
                                        'comparison' => 'ارفع صورتين: منتج MG وصورة المنافس أو قبل/بعد.',
                                        default => 'ارفع صورة واضحة أفقية (يفضّل عرض عريض للتطبيق).',
                                    })
                                    ->columnSpanFull(),

                                Forms\Components\FileUpload::make('image_path')
                                    ->label(fn (Get $get) => match ($get('media_type')) {
                                        'comparison' => 'صورة منتج MG',
                                        'video' => 'صورة غلاف الفيديو',
                                        default => 'صورة الإعلان',
                                    })
                                    ->disk('public')
                                    ->directory('product-promotions')
                                    ->image()
                                    ->imageEditor()
                                    ->imageEditorAspectRatios(['16:9', '4:3', '1:1', null])
                                    ->openable()
                                    ->downloadable()
                                    ->maxSize(5120)
                                    ->helperText('حتى 5MB — JPG / PNG / WebP')
                                    ->required(fn (Get $get) => in_array($get('media_type'), ['image', 'comparison'], true))
                                    ->columnSpanFull(),

                                Forms\Components\FileUpload::make('comparison_image_path')
                                    ->label('صورة المقارنة (منافس / قبل)')
                                    ->disk('public')
                                    ->directory('product-promotions')
                                    ->image()
                                    ->imageEditor()
                                    ->imageEditorAspectRatios(['16:9', '4:3', '1:1', null])
                                    ->openable()
                                    ->downloadable()
                                    ->maxSize(5120)
                                    ->visible(fn (Get $get) => $get('media_type') === 'comparison')
                                    ->required(fn (Get $get) => $get('media_type') === 'comparison')
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('video_url')
                                    ->label('رابط الفيديو')
                                    ->placeholder('https://www.youtube.com/watch?v=…')
                                    ->url()
                                    ->prefixIcon('heroicon-o-link')
                                    ->visible(fn (Get $get) => $get('media_type') === 'video')
                                    ->required(fn (Get $get) => $get('media_type') === 'video')
                                    ->helperText('يوتيوب أو فيميو أو رابط مباشر لملف فيديو')
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('ربط بمنتج MG')
                            ->description('اختياري — عند الضغط في التطبيق يفتح المنتج أو الرابط الخارجي.')
                            ->icon('heroicon-o-link')
                            ->collapsed()
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label('منتج من الكتالوج')
                                    ->relationship(
                                        name: 'product',
                                        titleAttribute: 'id',
                                        modifyQueryUsing: fn ($query) => $query->with('translations')->orderBy('id'),
                                    )
                                    ->getOptionLabelFromRecordUsing(
                                        fn (Product $p) => localized_name($p, 'name', "منتج #{$p->id}")
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->helperText('ابحث باسم المنتج')
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('product_url')
                                    ->label('أو رابط خارجي')
                                    ->placeholder('https://mg-plastic.com/…')
                                    ->url()
                                    ->prefixIcon('heroicon-o-globe-alt')
                                    ->helperText('إن وُجد يُفضَّل على رابط المنتج الداخلي')
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
                                    ->helperText('عطّله لإخفاء الإعلان دون حذفه.')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\TextInput::make('sort_order')
                                    ->label('ترتيب الظهور')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->helperText('الأقل يظهر أولاً'),
                            ]),

                        Forms\Components\Section::make('تذكير للموبايل')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->schema([
                                Forms\Components\Placeholder::make('api_hint')
                                    ->label('')
                                    ->content('يظهر عبر: GET /api/v1/mobile/product-promotions'),
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
                    ->size(64),

                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->weight('bold')
                    ->wrap()
                    ->limit(50),

                Tables\Columns\TextColumn::make('media_type')
                    ->label('النوع')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'video' => 'danger',
                        'comparison' => 'warning',
                        default => 'primary',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'video' => 'فيديو',
                        'comparison' => 'مقارنة',
                        default => 'صورة',
                    }),

                Tables\Columns\TextColumn::make('product_id')
                    ->label('المنتج')
                    ->formatStateUsing(fn ($state, ProductPromotion $record) => $record->product
                        ? localized_name($record->product, 'name')
                        : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('ترتيب')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('ظاهر')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('media_type')
                    ->label('النوع')
                    ->options([
                        'image' => 'صورة',
                        'video' => 'فيديو',
                        'comparison' => 'مقارنة',
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
            ->emptyStateDescription('أضف أول إعلان صورة أو فيديو أو مقارنة ليظهر في التطبيق.')
            ->emptyStateIcon('heroicon-o-megaphone');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductPromotions::route('/'),
            'create' => Pages\CreateProductPromotion::route('/create'),
            'edit' => Pages\EditProductPromotion::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\ProductCollectionResource\Pages;
use App\Models\Product;
use App\Models\ProductCollection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductCollectionResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = ProductCollection::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?int $navigationSort = 42;

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'مجموعات المنتجات';
    }

    public static function getModelLabel(): string
    {
        return 'مجموعة منتجات';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مجموعات المنتجات';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('اسم المجموعة ومحتواها')
                            ->description('سمّي المجموعة بأي اسم تبيه — يظهر كما هو في التطبيق، ويمكن تعديله لاحقاً في أي وقت.')
                            ->icon('heroicon-o-pencil-square')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('اسم المجموعة')
                                    ->placeholder('مثال: عروض الأنابيب · تشكيلة السباكين · منتجات الشتاء')
                                    ->helperText('اسم حر بالكامل — مش مربوط بقائمة ثابتة.')
                                    ->required()
                                    ->maxLength(200)
                                    ->autofocus()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Forms\Set $set, $livewire) {
                                        // فقط عند الإنشاء: توليد slug تلقائي (مخفي عن المستخدم)
                                        if ($livewire instanceof Pages\EditProductCollection || ! filled($state)) {
                                            return;
                                        }
                                        $set('slug', ProductCollection::uniqueSlugFor('', (string) $state));
                                    })
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('subtitle')
                                    ->label('عنوان فرعي')
                                    ->placeholder('سطر قصير تحت الاسم (اختياري)')
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('description')
                                    ->label('وصف المجموعة')
                                    ->placeholder('اكتب نبذة تظهر في صفحة المجموعة داخل التطبيق…')
                                    ->rows(4)
                                    ->columnSpanFull(),

                                Forms\Components\Hidden::make('slug')
                                    ->dehydrated()
                                    ->default(fn () => 'collection-'.Str::lower(Str::random(6))),
                            ]),

                        Forms\Components\Section::make('تصنيف العرض')
                            ->description('التصنيف للتنظيم والفلترة في التطبيق — الاسم الظاهر هو «اسم المجموعة» أعلاه.')
                            ->icon('heroicon-o-tag')
                            ->schema([
                                Forms\Components\ToggleButtons::make('kind')
                                    ->label('نوع المجموعة')
                                    ->options([
                                        'featured' => 'مميزة',
                                        'new' => 'حديثة',
                                        'important' => 'مهمة',
                                        'custom' => 'مخصّصة',
                                    ])
                                    ->icons([
                                        'featured' => 'heroicon-o-star',
                                        'new' => 'heroicon-o-sparkles',
                                        'important' => 'heroicon-o-fire',
                                        'custom' => 'heroicon-o-squares-plus',
                                    ])
                                    ->colors([
                                        'featured' => 'warning',
                                        'new' => 'success',
                                        'important' => 'danger',
                                        'custom' => 'primary',
                                    ])
                                    ->inline()
                                    ->required()
                                    ->default('custom')
                                    ->helperText('اختر «مخصّصة» لو الاسم خاص بيك ومش من الأنواع الجاهزة.')
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Section::make('صورة الغلاف والمنتجات')
                            ->description('الغلاف اختياري. اختر المنتجات اللي تدخل في المجموعة.')
                            ->icon('heroicon-o-cube')
                            ->schema([
                                Forms\Components\FileUpload::make('cover_image')
                                    ->label('صورة الغلاف')
                                    ->disk('public')
                                    ->directory('product-collections')
                                    ->image()
                                    ->imageEditor()
                                    ->imageEditorAspectRatios(['16:9', '4:3', '1:1', null])
                                    ->imagePreviewHeight('200')
                                    ->openable()
                                    ->downloadable()
                                    ->maxSize(5120)
                                    ->helperText('يفضّل صورة أفقية واضحة — حتى 5MB')
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('products')
                                    ->label('منتجات المجموعة')
                                    ->relationship('products', 'id')
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->getOptionLabelFromRecordUsing(
                                        fn (Product $p) => localized_name($p, 'name', "منتج #{$p->id}")
                                    )
                                    ->helperText('ابحث بالاسم وأضف أكثر من منتج. يمكن التعديل لاحقاً.')
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
                                    ->label('ظاهرة في التطبيق')
                                    ->helperText('عطّلها لإخفاء المجموعة دون حذفها.')
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
                                    ->content('القائمة: GET /api/v1/mobile/product-collections\nالتفاصيل: GET /api/v1/mobile/product-collections/{id}\nفلترة: ?kind=featured|new|important|custom'),
                            ]),

                        Forms\Components\Section::make('تنبيه')
                            ->icon('heroicon-o-information-circle')
                            ->collapsed()
                            ->schema([
                                Forms\Components\Placeholder::make('rename_hint')
                                    ->label('')
                                    ->content('تقدر تغيّر اسم المجموعة في أي وقت من صفحة التعديل — الاسم الجديد يظهر فوراً في التطبيق.'),
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
                Tables\Columns\ImageColumn::make('cover_image')
                    ->label('غلاف')
                    ->disk('public')
                    ->square()
                    ->size(64),

                Tables\Columns\TextColumn::make('title')
                    ->label('اسم المجموعة')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap()
                    ->limit(50)
                    ->description(fn (ProductCollection $r) => $r->subtitle),

                Tables\Columns\TextColumn::make('kind')
                    ->label('التصنيف')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'featured' => 'warning',
                        'new' => 'success',
                        'important' => 'danger',
                        default => 'primary',
                    })
                    ->formatStateUsing(fn (string $state, ProductCollection $r) => $r->kindLabel()),

                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('منتجات')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('ترتيب')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('ظاهرة')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label('التصنيف')
                    ->options([
                        'featured' => 'مميزة',
                        'new' => 'حديثة',
                        'important' => 'مهمة',
                        'custom' => 'مخصّصة',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('ظاهرة في التطبيق'),
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
            ->emptyStateHeading('لا توجد مجموعات بعد')
            ->emptyStateDescription('أضف مجموعة باسمك الخاص واختر المنتجات اللي تظهر فيها.')
            ->emptyStateIcon('heroicon-o-rectangle-group');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductCollections::route('/'),
            'create' => Pages\CreateProductCollection::route('/create'),
            'edit' => Pages\EditProductCollection::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\PriceListResource\Pages;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use App\Services\PriceListProductQuery;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PriceListResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = PriceList::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'قوائم الأسعار';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'قائمة أسعار';

    protected static ?string $pluralModelLabel = 'قوائم الأسعار';

    public static function getNavigationLabel(): string
    {
        return 'قوائم الأسعار';
    }

    public static function getNavigationBadge(): ?string
    {
        $drafts = PriceList::query()->where('status', 'draft')->count();

        return $drafts > 0 ? (string) $drafts : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات العرض')
                ->description('اسم العميل اختياري. القائمة تقدر تتبعت من غير ما العميل يبقى مسجّل.')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('عنوان القائمة')
                        ->placeholder('قائمة أسعار الملحقات (السوبر)')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('subtitle')
                        ->label('السطر الثاني')
                        ->placeholder('حسب النظام الأوروبي')
                        ->maxLength(255)
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('customer_company')
                        ->label('الشركة')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('customer_name')
                        ->label('اسم العميل')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('customer_phone')
                        ->label('هاتف العميل')
                        ->tel()
                        ->maxLength(40),
                    Forms\Components\DatePicker::make('issued_on')
                        ->label('التاريخ')
                        ->default(now())
                        ->required()
                        ->native(false),
                    Forms\Components\DatePicker::make('valid_until')
                        ->label('صالحة حتى')
                        ->native(false),
                    Forms\Components\Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'draft' => 'مسودة',
                            'final' => 'نهائي',
                        ])
                        ->default('draft')
                        ->required(),
                    Forms\Components\Textarea::make('notes')
                        ->label('ملاحظة أسفل الجدول')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(4),

            Forms\Components\Section::make('إضافة منتجات')
                ->description('فلتر ثم أضف الكل دفعة واحدة. المنتج الموجود في الجدول لا يتكرر.')
                ->schema([
                    Forms\Components\Select::make('filter_parent_id')
                        ->label('الفئة')
                        ->options(fn () => static::categoryOptions(null))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->dehydrated(false)
                        ->afterStateUpdated(fn (Set $set) => $set('filter_category_id', null)),
                    Forms\Components\Select::make('filter_category_id')
                        ->label('التصنيف')
                        ->options(fn (Get $get) => static::categoryOptions($get('filter_parent_id') ? (int) $get('filter_parent_id') : null))
                        ->searchable()
                        ->preload()
                        ->dehydrated(false),
                    Forms\Components\Select::make('filter_tag')
                        ->label('الوسم')
                        ->options(fn () => ProductTag::query()->orderBy('name')->pluck('name', 'name'))
                        ->searchable()
                        ->preload()
                        ->dehydrated(false),
                    Forms\Components\TextInput::make('filter_search')
                        ->label('بحث')
                        ->placeholder('اسم المنتج')
                        ->dehydrated(false),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('add_filtered')
                            ->label('إضافة النتائج')
                            ->icon('heroicon-o-plus-circle')
                            ->action(function (Get $get, Set $set) {
                                static::appendFilteredProducts($get, $set);
                            }),
                    ])->columnSpanFull(),
                ])
                ->columns(4),

            Forms\Components\Section::make('بنود القائمة')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->orderColumn('sort')
                        ->reorderable()
                        ->reorderableWithDragAndDrop()
                        ->collapsible()
                        ->cloneable()
                        ->itemLabel(fn (array $state): ?string => $state['item_name'] ?? null)
                        ->addActionLabel('سطر يدوي')
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('منتج من الكتالوج')
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search) {
                                    return Product::query()
                                        ->search($search)
                                        ->limit(30)
                                        ->get()
                                        ->mapWithKeys(fn (Product $product) => [
                                            $product->id => localized_name($product, 'name', 'منتج #'.$product->id),
                                        ]);
                                })
                                ->getOptionLabelUsing(function ($value) {
                                    $product = Product::query()->find($value);

                                    return $product
                                        ? localized_name($product, 'name', 'منتج #'.$product->id)
                                        : null;
                                })
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set) {
                                    if (! $state) {
                                        return;
                                    }
                                    $product = Product::query()->with('translations')->find($state);
                                    if (! $product) {
                                        return;
                                    }
                                    foreach ($product->quoteLinePayload() as $key => $value) {
                                        if ($key !== 'product_id') {
                                            $set($key, $value);
                                        }
                                    }
                                })
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('item_name')
                                ->label('نوع الصنف')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('pieces_per_box')
                                ->label('عبوة الصندوق')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('قطعة'),
                            Forms\Components\TextInput::make('piece_price_aed')
                                ->label('سعر القطعة (درهم)')
                                ->numeric()
                                ->default(0),
                            Forms\Components\TextInput::make('piece_price_lyd')
                                ->label('سعر القطعة (دينار)')
                                ->numeric()
                                ->default(0),
                            Forms\Components\TextInput::make('box_price_aed')
                                ->label('سعر الصندوق (درهم)')
                                ->numeric()
                                ->default(0),
                            Forms\Components\TextInput::make('box_price_lyd')
                                ->label('سعر الصندوق (دينار)')
                                ->numeric()
                                ->default(0),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('الرقم')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('customer_company')
                    ->label('العميل')
                    ->formatStateUsing(fn (PriceList $record) => $record->customerLabel() ?: '—')
                    ->searchable(['customer_company', 'customer_name']),
                Tables\Columns\TextColumn::make('items_count')
                    ->counts('items')
                    ->label('أصناف')
                    ->badge(),
                Tables\Columns\TextColumn::make('issued_on')
                    ->label('التاريخ')
                    ->date('Y/m/d')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'final' ? 'نهائي' : 'مسودة')
                    ->color(fn (string $state) => $state === 'final' ? 'success' : 'warning'),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\Action::make('print')
                    ->label('طباعة')
                    ->icon('heroicon-o-printer')
                    ->url(fn (PriceList $record) => route('admin.price-lists.print', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make()->label('تعديل'),
            ])
            ->emptyStateHeading('لا توجد قوائم أسعار')
            ->emptyStateDescription('أنشئ أول عرض سعر، أضف المنتجات بالفلتر، ثم اطبعه أو أرسل رابطه للعميل.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceLists::route('/'),
            'create' => Pages\CreatePriceList::route('/create'),
            'edit' => Pages\EditPriceList::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private static function categoryOptions(?int $parentId): array
    {
        $query = ProductCategory::query()->with('translations')->orderBy('id');
        if ($parentId) {
            $query->where('parent_id', $parentId);
        } else {
            $query->whereNull('parent_id');
        }

        return $query->get()->mapWithKeys(fn (ProductCategory $category) => [
            $category->id => localized_name($category, 'name', 'تصنيف #'.$category->id),
        ])->all();
    }

    private static function appendFilteredProducts(Get $get, Set $set): void
    {
        $current = $get('items') ?? [];
        if (! is_array($current)) {
            $current = [];
        }

        $except = collect($current)
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $products = app(PriceListProductQuery::class)->matching(
            $get('filter_parent_id') ? (int) $get('filter_parent_id') : null,
            $get('filter_category_id') ? (int) $get('filter_category_id') : null,
            $get('filter_tag') ? (string) $get('filter_tag') : null,
            $get('filter_search') ? (string) $get('filter_search') : null,
            $except,
            251,
        );

        if ($products->isEmpty()) {
            Notification::make()->title('لا توجد منتجات مطابقة')->warning()->send();

            return;
        }

        $truncated = $products->count() > 250;
        $sort = count($current);
        foreach ($products->take(250) as $product) {
            $sort++;
            $current[(string) Str::uuid()] = $product->quoteLinePayload() + ['sort' => $sort];
        }

        $set('items', $current);

        $added = min(250, $products->count());
        $notice = Notification::make()->title('تمت إضافة '.$added.' منتج')->success();
        if ($truncated) {
            $notice->body('ضيّق الفلتر لإضافة الباقي. الحد في المرة الواحدة 250.');
        }
        $notice->send();
    }
}

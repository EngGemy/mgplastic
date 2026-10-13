<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\PriceListResource\Pages;
use App\Models\FactoryCustomer;
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
            Forms\Components\Grid::make(12)->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Select::make('factory_customer_id')
                            ->label('عميل المصنع')
                            ->relationship('customer', 'company_name')
                            ->getOptionLabelFromRecordUsing(fn (FactoryCustomer $record) => $record->optionLabel())
                            ->searchable(['company_name', 'contact_name', 'phone'])
                            ->preload()
                            ->native(false)
                            ->placeholder('ابحث بالشركة أو الهاتف، أو أضف عميلاً جديداً')
                            ->createOptionModalHeading('عميل مصنع جديد')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('company_name')
                                    ->label('اسم الشركة أو العميل')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('contact_name')
                                    ->label('مسؤول التواصل')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('phone')
                                    ->label('الهاتف')
                                    ->tel()
                                    ->maxLength(40),
                            ])
                            ->createOptionUsing(fn (array $data) => FactoryCustomer::query()->create($data)->getKey())
                            ->helperText('عملاء المصنع منفصلون عن مستخدمي النظام.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('title')
                            ->label('عنوان العرض')
                            ->placeholder('قائمة أسعار الملحقات (السوبر)')
                            ->required()
                            ->maxLength(255)
                            ->default('قائمة أسعار'),
                        Forms\Components\TextInput::make('subtitle')
                            ->label('السطر الثاني')
                            ->placeholder('حسب النظام الأوروبي')
                            ->maxLength(255),
                    ])
                    ->columnSpan(8),
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\DatePicker::make('issued_on')
                            ->label('التاريخ')
                            ->default(now())
                            ->required()
                            ->native(false),
                        Forms\Components\DatePicker::make('valid_until')
                            ->label('صالح حتى')
                            ->native(false),
                        Forms\Components\Select::make('status')
                            ->label('الحالة')
                            ->options([
                                'draft' => 'مسودة',
                                'final' => 'نهائي',
                            ])
                            ->default('draft')
                            ->native(false)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظة')
                            ->rows(2)
                            ->placeholder('تظهر أسفل الجدول'),
                    ])
                    ->columnSpan(4),
            ]),

            Forms\Components\Section::make('الأصناف')
                ->description('اكتب اسم المنتج واختره ليُضاف بسعر القطعة وسعر الصندوق.')
                ->schema([
                    Forms\Components\Select::make('quick_product')
                        ->hiddenLabel()
                        ->placeholder('أضف منتجاً…')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => static::productSearchOptions($search))
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                            if (! $state) {
                                return;
                            }
                            static::appendProductIds($get, $set, [(int) $state]);
                            $set('quick_product', null);
                        })
                        ->columnSpanFull(),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('add_group')
                            ->label('إضافة تصنيف كامل')
                            ->icon('heroicon-o-squares-plus')
                            ->color('gray')
                            ->modalHeading('إضافة منتجات تصنيف')
                            ->modalSubmitActionLabel('إضافة')
                            ->form([
                                Forms\Components\Select::make('parent_id')
                                    ->label('الفئة')
                                    ->options(fn () => static::categoryOptions(null))
                                    ->searchable()
                                    ->preload()
                                    ->live(),
                                Forms\Components\Select::make('category_id')
                                    ->label('التصنيف')
                                    ->options(fn (Get $get) => static::categoryOptions($get('parent_id') ? (int) $get('parent_id') : null, true))
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\Select::make('tag')
                                    ->label('الوسم')
                                    ->options(fn () => ProductTag::query()->orderBy('name')->pluck('name', 'name'))
                                    ->searchable(),
                                Forms\Components\TextInput::make('search')
                                    ->label('بحث داخل التصنيف'),
                            ])
                            ->action(function (array $data, \Livewire\Component $livewire) {
                                $state = $livewire->data;
                                $items = $state['items'] ?? [];
                                $products = app(PriceListProductQuery::class)->matching(
                                    filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null,
                                    filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null,
                                    $data['tag'] ?? null,
                                    $data['search'] ?? null,
                                    static::existingProductIds(is_array($items) ? $items : []),
                                    251,
                                );

                                $ids = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
                                $except = static::existingProductIds(is_array($items) ? $items : []);
                                $ids = array_values(array_diff($ids, $except));
                                if ($ids === []) {
                                    Notification::make()->title('لا توجد منتجات جديدة للإضافة')->warning()->send();

                                    return;
                                }

                                $found = Product::query()->with('translations')->whereIn('id', array_slice($ids, 0, 250))->get();
                                $sort = is_array($items) ? count($items) : 0;
                                foreach ($found as $product) {
                                    $sort++;
                                    $items[(string) Str::uuid()] = $product->quoteLinePayload() + ['sort' => $sort];
                                }
                                $state['items'] = $items;
                                $livewire->data = $state;

                                Notification::make()->title('تمت إضافة '.$found->count().' منتج')->success()->send();
                            }),
                    ]),
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->orderColumn('sort')
                        ->reorderable()
                        ->reorderableWithDragAndDrop()
                        ->addActionLabel('سطر يدوي')
                        ->extraAttributes(['class' => 'mg-quote-lines'])
                        ->schema([
                            Forms\Components\TextInput::make('item_name')
                                ->label('الصنف')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(4),
                            Forms\Components\TextInput::make('pieces_per_box')
                                ->label('العبوة')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('قطعة')
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('piece_price_aed')
                                ->label('قطعة درهم')
                                ->numeric()
                                ->default(0)
                                ->columnSpan(1),
                            Forms\Components\TextInput::make('piece_price_lyd')
                                ->label('قطعة دينار')
                                ->numeric()
                                ->default(0)
                                ->columnSpan(1),
                            Forms\Components\TextInput::make('box_price_aed')
                                ->label('صندوق درهم')
                                ->numeric()
                                ->default(0)
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('box_price_lyd')
                                ->label('صندوق دينار')
                                ->numeric()
                                ->default(0)
                                ->columnSpan(2),
                            Forms\Components\Hidden::make('product_id'),
                        ])
                        ->columns(12)
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
                Tables\Columns\TextColumn::make('customer.company_name')
                    ->label('العميل')
                    ->formatStateUsing(fn (PriceList $record) => $record->customerLabel() ?: '—')
                    ->searchable(),
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
            ->emptyStateHeading('لا توجد عروض أسعار')
            ->emptyStateDescription('ابحث عن عميل المصنع، أضف المنتجات، ثم اطبع أو أرسل الرابط.')
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
    private static function categoryOptions(?int $parentId, bool $children = false): array
    {
        $query = ProductCategory::query()->with('translations')->orderBy('id');
        if ($parentId) {
            $query->where('parent_id', $parentId);
        } elseif ($children) {
            $query->whereNotNull('parent_id');
        } else {
            $query->whereNull('parent_id');
        }

        return $query->get()->mapWithKeys(fn (ProductCategory $category) => [
            $category->id => localized_name($category, 'name', 'تصنيف #'.$category->id),
        ])->all();
    }

    /**
     * @return array<int, string>
     */
    private static function productSearchOptions(string $search): array
    {
        return Product::query()
            ->search($search)
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (Product $product) => [
                $product->id => localized_name($product, 'name', 'منتج #'.$product->id),
            ])
            ->all();
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<int>
     */
    private static function existingProductIds(array $items): array
    {
        return collect($items)
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $productIds
     */
    private static function appendProductIds(Get $get, Set $set, array $productIds): void
    {
        $current = $get('items') ?? [];
        if (! is_array($current)) {
            $current = [];
        }

        $except = static::existingProductIds($current);
        $productIds = array_values(array_diff(array_unique($productIds), $except));

        if ($productIds === []) {
            Notification::make()->title('لا توجد منتجات جديدة للإضافة')->warning()->send();

            return;
        }

        $truncated = count($productIds) > 250;
        $products = Product::query()->with('translations')->whereIn('id', array_slice($productIds, 0, 250))->get();
        $sort = count($current);
        foreach ($products as $product) {
            $sort++;
            $current[(string) Str::uuid()] = $product->quoteLinePayload() + ['sort' => $sort];
        }

        $set('items', $current);

        $notice = Notification::make()->title('تمت إضافة '.$products->count().' منتج')->success();
        if ($truncated) {
            $notice->body('الحد في المرة الواحدة 250 منتج.');
        }
        $notice->send();
    }
}

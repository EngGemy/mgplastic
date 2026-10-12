<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Concerns\HasProductWizardForm;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SystemLabel;
use Filament\Forms\Form;
use Filament\Infolists\Components as Info;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    use AdminOnlyResource;
    use HasProductWizardForm;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'المنتجات';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return SystemLabel::get('products', 'المنتجات');
    }

    public static function getPluralModelLabel(): string
    {
        return SystemLabel::get('products', 'المنتجات');
    }

    public static function getModelLabel(): string
    {
        return SystemLabel::get('products', 'منتج');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'المنتجات';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'translations',
                'category.parent',
                'category.translations',
                'images',
                'tags',
            ]);
    }

    public static function form(Form $form): Form
    {
        return static::editTabbedForm($form);
    }

    public static function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table
            ->striped()
            ->paginated([12, 24, 48, 100])
            ->defaultPaginationPageOption(24)
            ->deferFilters()
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->searchPlaceholder('ابحث بالاسم / الوصف / التصنيف / رقم المنتج…')
            ->emptyStateHeading('لا توجد منتجات')
            ->emptyStateDescription('الكتالوج فاضي حالياً — أضف منتجاً أو ارفع شيت المنتجات دفعة واحدة.')
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة منتج')
                    ->url(fn () => static::getUrl('create')),
            ])
            ->columns([
                Tables\Columns\ImageColumn::make('main_image')
                    ->label('الصورة')
                    ->getStateUsing(fn (Product $record) => $record->main_image ?: $record->catalog_image_path)
                    ->disk('public')
                    ->square()
                    ->size(52),

                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('المنتج')
                    ->state(fn (Product $record) => optional($record->translate($locale) ?: $record->translate('en') ?: $record->translate('ar'))?->name ?: 'منتج #'.$record->id)
                    ->description(function (Product $record) use ($locale) {
                        $cat = $record->category;
                        if (! $cat) {
                            return null;
                        }
                        $parentName = $cat->parent?->translateOrDefault($locale)?->name
                            ?? $cat->parent?->translate('en')?->name;
                        $childName = $cat->translateOrDefault($locale)?->name
                            ?? $cat->translate('en')?->name;

                        return $parentName ? "#{$cat->id} · {$parentName} ← {$childName}" : "#{$cat->id} · {$childName}";
                    })
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->search($search)),

                Tables\Columns\TextColumn::make('classification')
                    ->label('الدرجة')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('tags.name')
                    ->label('الوسوم')
                    ->badge()
                    ->color('info')
                    ->separator(',')
                    ->limitList(3)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('points_per_unit')
                    ->label('النقاط')
                    ->badge()
                    ->color(fn ($state) => ((float) $state) > 0 ? 'success' : 'gray')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('point_conversion')
                    ->label('تحويل النقاط')
                    ->state(fn (Product $p) => $p->pointConversionSummary())
                    ->badge()
                    ->color('warning')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('catalog_image_path')
                    ->label('دليل صورة')
                    ->boolean()
                    ->getStateUsing(fn (Product $p) => filled($p->catalog_image_path))
                    ->trueIcon('heroicon-o-photo')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('catalog_pdf_url')
                    ->label('PDF')
                    ->formatStateUsing(fn ($state) => $state ? 'فتح' : '—')
                    ->url(fn (Product $record) => $record->catalog_pdf_url ?: null, true)
                    ->openUrlInNewTab()
                    ->badge()
                    ->color(fn (Product $record) => $record->catalog_pdf_url ? 'info' : 'gray')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('images_count')
                    ->counts('images')
                    ->label('صور')
                    ->badge()
                    ->color('warning')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('length_m')
                    ->label('طول (م)')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('thickness_mm')
                    ->label('سُمك (مم)')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('volume_ml')
                    ->label('حجم (مل)')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('unit_point_value')
                    ->label('قيمة الوحدة')
                    ->state(fn (Product $p) => number_format($p->pointMonetaryValuePerUnit(), 2).' د.ل')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('parent_category')
                    ->label('الفئة الرئيسية')
                    ->options(fn () => static::categoryOptions($locale, parentsOnly: true))
                    ->searchable()
                    ->preload()
                    ->query(function (Builder $query, array $data): Builder {
                        $id = $data['value'] ?? null;

                        if (blank($id)) {
                            return $query;
                        }

                        return $query->where(function (Builder $inner) use ($id) {
                            $inner->where('product_category_id', $id)
                                ->orWhereIn('product_category_id', function ($sub) use ($id) {
                                    $sub->from('product_categories')
                                        ->select('id')
                                        ->where('parent_id', $id);
                                });
                        });
                    }),

                Tables\Filters\SelectFilter::make('product_category_id')
                    ->label('الفئة الفرعية')
                    ->options(fn () => static::categoryOptions($locale, parentsOnly: false, childrenOnly: true))
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('classification')
                    ->label('الدرجة')
                    ->options(function () {
                        try {
                            return Product::query()
                                ->whereNotNull('classification')
                                ->where('classification', '!=', '')
                                ->distinct()
                                ->orderBy('classification')
                                ->pluck('classification', 'classification')
                                ->all();
                        } catch (\Throwable) {
                            return [];
                        }
                    })
                    ->searchable(),

                Tables\Filters\TernaryFilter::make('has_points')
                    ->label('عليه نقاط')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لا')
                    ->queries(
                        true: fn (Builder $q) => $q->where('points_per_unit', '>', 0),
                        false: fn (Builder $q) => $q->where(function (Builder $inner) {
                            $inner->whereNull('points_per_unit')->orWhere('points_per_unit', '<=', 0);
                        }),
                    ),

                Tables\Filters\TernaryFilter::make('has_catalog_pdf')
                    ->label('يوجد PDF')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لا')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('catalog_pdf_path')->where('catalog_pdf_path', '!=', ''),
                        false: fn (Builder $q) => $q->where(function (Builder $inner) {
                            $inner->whereNull('catalog_pdf_path')->orWhere('catalog_pdf_path', '');
                        }),
                    ),

                Tables\Filters\TernaryFilter::make('has_catalog_image')
                    ->label('يوجد صورة دليل')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لا')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('catalog_image_path')->where('catalog_image_path', '!=', ''),
                        false: fn (Builder $q) => $q->where(function (Builder $inner) {
                            $inner->whereNull('catalog_image_path')->orWhere('catalog_image_path', '');
                        }),
                    ),

                Tables\Filters\TernaryFilter::make('has_gallery')
                    ->label('معرض صور')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لا')
                    ->queries(
                        true: fn (Builder $q) => $q->has('images'),
                        false: fn (Builder $q) => $q->doesntHave('images'),
                    ),

                Tables\Filters\Filter::make('points_range')
                    ->label('نطاق النقاط')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('min')->label('من')->numeric(),
                        \Filament\Forms\Components\TextInput::make('max')->label('إلى')->numeric(),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['min'] ?? null), fn (Builder $q) => $q->where('points_per_unit', '>=', (float) $data['min']))
                            ->when(filled($data['max'] ?? null), fn (Builder $q) => $q->where('points_per_unit', '<=', (float) $data['max']));
                    })
                    ->indicateUsing(function (array $data): array {
                        $bits = [];
                        if (filled($data['min'] ?? null)) {
                            $bits[] = 'من '.$data['min'];
                        }
                        if (filled($data['max'] ?? null)) {
                            $bits[] = 'إلى '.$data['max'];
                        }

                        return $bits === [] ? [] : ['نطاق النقاط: '.implode(' ', $bits)];
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('عرض'),
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * @return array<int|string, string>
     */
    protected static function categoryOptions(string $locale, bool $parentsOnly = false, bool $childrenOnly = false): array
    {
        $query = ProductCategory::query()->with('translations');

        if ($parentsOnly) {
            $query->whereNull('parent_id');
        } elseif ($childrenOnly) {
            $query->whereNotNull('parent_id')->with('parent.translations');
        }

        return $query->get()
            ->mapWithKeys(function (ProductCategory $c) use ($locale, $childrenOnly) {
                $name = $c->translateOrDefault($locale)?->name
                    ?? $c->translate('en')?->name
                    ?? "Category #{$c->id}";

                if ($childrenOnly && $c->parent) {
                    $parent = $c->parent->translateOrDefault($locale)?->name
                        ?? $c->parent->translate('en')?->name;
                    if ($parent) {
                        $name = "{$parent} ← {$name}";
                    }
                }

                return [$c->id => $name];
            })
            ->sortBy(fn ($name) => mb_strtolower($name))
            ->all();
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $locale = app()->getLocale();

        return $infolist->schema([
            Info\ViewEntry::make('product_header')
                ->view('filament.infolists.product-profile-header')
                ->columnSpanFull(),

            Info\Section::make('المواصفات')
                ->icon('heroicon-o-cube')
                ->schema([
                    Info\Grid::make(3)->schema([
                        Info\TextEntry::make('category_path')
                            ->label('الفئة')
                            ->state(function (Product $record) {
                                $cat = $record->category?->loadMissing('parent', 'translations', 'parent.translations');
                                if (! $cat) {
                                    return '—';
                                }
                                $parentName = $cat->parent ? localized_name($cat->parent, 'name') : null;
                                $childName = localized_name($cat, 'name');

                                return $parentName ? "#{$cat->id} · {$parentName} ← {$childName}" : "#{$cat->id} · {$childName}";
                            })
                            ->badge()
                            ->color('info'),

                        Info\TextEntry::make('classification')
                            ->label('الدرجة')
                            ->badge()
                            ->color('gray')
                            ->default('—'),

                        Info\TextEntry::make('points_per_unit')
                            ->label('النقاط/وحدة')
                            ->badge()
                            ->color('success')
                            ->default('—'),

                        Info\TextEntry::make('point_value_type')
                            ->label('نوع التحويل')
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'percent' => 'نسبة',
                                'fixed' => 'ثابت',
                                default => '—',
                            })
                            ->badge(),

                        Info\TextEntry::make('point_conversion_detail')
                            ->label('قيمة التحويل')
                            ->state(fn (Product $record) => $record->pointConversionSummary()),

                        Info\TextEntry::make('point_monetary_per_unit')
                            ->label('قيمة نقاط الوحدة')
                            ->state(fn (Product $record) => number_format($record->pointMonetaryValuePerUnit(), 2).' د.ل')
                            ->color('success')
                            ->weight('bold'),

                        Info\TextEntry::make('length_m')
                            ->label('الطول (م)')
                            ->default('—'),

                        Info\TextEntry::make('thickness_mm')
                            ->label('السُمك (مم)')
                            ->default('—'),

                        Info\TextEntry::make('volume_ml')
                            ->label('الحجم (مل)')
                            ->default('—'),
                    ]),
                ]),

            Info\Section::make('دليل المنتج')
                ->icon('heroicon-o-document')
                ->schema([
                    Info\ImageEntry::make('catalog_image_path')
                        ->label('صورة الدليل')
                        ->disk('public')
                        ->height(140)
                        ->extraImgAttributes(['class' => 'rounded-xl'])
                        ->visible(fn (Product $r) => (bool) $r->catalog_image_path),

                    Info\TextEntry::make('catalog_pdf_link')
                        ->label('ملف PDF')
                        ->state(function (Product $r) {
                            if (! $r->catalog_pdf_url) {
                                return '—';
                            }
                            $url = e($r->catalog_pdf_url);

                            return '<a href="'.$url.'" target="_blank" class="underline text-primary-600">فتح PDF</a>';
                        })
                        ->html(),
                ])
                ->visible(fn (Product $r) => $r->catalog_image_path || $r->catalog_pdf_url)
                ->collapsed(),

            Info\Section::make('معرض الصور')
                ->icon('heroicon-o-photo')
                ->schema([
                    Info\RepeatableEntry::make('images')
                        ->label('')
                        ->schema([
                            Info\ImageEntry::make('image')->disk('public')->height(100)->extraImgAttributes(['class' => 'rounded-lg']),
                            Info\TextEntry::make('sort')->label('الترتيب')->badge(),
                        ])
                        ->grid(4),
                ])
                ->visible(fn (Product $r) => $r->images()->exists())
                ->collapsible(),

            Info\Section::make('ملاحظات')
                ->icon('heroicon-o-document-text')
                ->schema([
                    Info\TextEntry::make('notes')->label('')->prose()->markdown()->default('—'),
                ])
                ->visible(fn (Product $r) => filled($r->notes))
                ->collapsible(),
        ]);
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record) {
            return null;
        }

        $locale = app()->getLocale();

        /** @var \App\Models\Product $record */
        $name =
            $record->translate($locale)?->name
            ?? $record->translate('en')?->name
            ?? $record->translate('ar')?->name;

        return $name ?: "Product #{$record->getKey()}";
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}

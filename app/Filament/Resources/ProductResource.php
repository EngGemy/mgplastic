<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Concerns\HasProductWizardForm;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SystemLabel;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components as Info;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Support\Htmlable;

class ProductResource extends Resource
{
    use AdminOnlyResource;
    use HasProductWizardForm;

    protected static ?string $model = Product::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'المنتجات';
    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string { return SystemLabel::get('products', 'المنتجات'); }
    public static function getPluralModelLabel(): string { return SystemLabel::get('products', 'المنتجات'); }
    public static function getModelLabel(): string { return SystemLabel::get('products', 'منتج'); }

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
            ->columns([
                Tables\Columns\ImageColumn::make('main_image')->label(__('Image'))->disk('public')->circular()->size(56),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('Name'))
                    ->state(fn (Product $record) => optional($record->translate($locale) ?: $record->translate('en'))?->name)
                    ->wrap()
                    ->limit(50)
                    ->searchable(query: fn (Builder $q, string $s) => $q->whereTranslationLike('name', "%{$s}%")),

                Tables\Columns\TextColumn::make('category_path')
                    ->label(__('Category'))
                    ->state(function (Product $record) use ($locale) {
                        $cat = $record->category?->loadMissing('parent','translations','parent.translations');
                        if (!$cat) return '—';
                        $parentName = $cat->parent?->translateOrDefault($locale)?->name ?? $cat->parent?->translate('en')?->name;
                        $childName  = $cat->translateOrDefault($locale)?->name ?? $cat->translate('en')?->name;
                        return $parentName ? "{$parentName} → {$childName}" : $childName;
                    })
                    ->badge()
                    ->color('info'),

                // Catalog presence badges
                Tables\Columns\IconColumn::make('has_catalog_image')
                    ->label(__('Cat. Image'))
                    ->boolean()
                    ->trueIcon('heroicon-o-photo')
                    ->falseIcon('heroicon-o-x-circle')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('has_catalog_pdf')
                    ->label(__('Cat. PDF'))
                    ->boolean()
                    ->trueIcon('heroicon-o-document-text')
                    ->falseIcon('heroicon-o-x-circle')
                    ->toggleable(),

                // Quick link to open PDF if exists
                Tables\Columns\TextColumn::make('catalog_pdf_url')
                    ->label(__('Open PDF'))
                    ->formatStateUsing(fn ($state) => $state ? __('View') : '—')
                    ->url(fn (Product $record) => $record->catalog_pdf_url ?: null, true)
                    ->openUrlInNewTab()
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('classification')->label(__('Class'))->badge()->toggleable(),
                Tables\Columns\TextColumn::make('length_m')->label(__('L (m)'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('thickness_mm')->label(__('T (mm)'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('volume_ml')->label(__('V (ml)'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('images_count')->counts('images')->label(__('Photos'))->badge()->color('warning')->sortable(),

                Tables\Columns\TextColumn::make('points_per_unit')
                    ->label('النقاط/وحدة')
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('point_conversion')
                    ->label('تحويل النقاط')
                    ->state(fn (Product $p) => $p->pointConversionSummary())
                    ->badge()
                    ->color('warning')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('unit_point_value')
                    ->label('قيمة الوحدة (د.ل)')
                    ->state(fn (Product $p) => number_format($p->pointMonetaryValuePerUnit(), 2).' د.ل')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')->label(__('Updated'))->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('parent_category')
                    ->label(__('Parent'))
                    ->options(function () use ($locale) {
                        return ProductCategory::query()
                            ->whereNull('parent_id')
                            ->with('translations')
                            ->get()
                            ->mapWithKeys(function ($c) use ($locale) {
                                $name = $c->translateOrDefault($locale)?->name
                                    ?? $c->translate('en')?->name
                                    ?? "Category #{$c->id}";
                                return [$c->id => $name];
                            })
                            ->sortBy(fn ($name) => mb_strtolower($name))
                            ->toArray();
                    })
                    ->indicateUsing(function ($state) use ($locale) {
                        if (empty($state)) return [];
                        $ids = is_array($state) ? $state : [$state];
                        $labels = ProductCategory::query()
                            ->whereIn('id', $ids)
                            ->with('translations')
                            ->get()
                            ->map(function (ProductCategory $c) use ($locale) {
                                return $c->translateOrDefault($locale)?->name
                                    ?? $c->translate('en')?->name
                                    ?? "Category #{$c->id}";
                            })
                            ->implode(', ');
                        return [__('Parent') => $labels];
                    })
                    ->query(function (Builder $q, $state) {
                        if (empty($state)) return;
                        $ids = is_array($state) ? $state : [$state];
                        $q->whereIn('product_category_id', function ($sub) use ($ids) {
                            $sub->from('product_categories')
                                ->select('id')
                                ->whereIn('parent_id', $ids);
                        });
                    }),

                Tables\Filters\SelectFilter::make('product_category_id')
                    ->label(__('Child'))
                    ->options(function () use ($locale) {
                        return ProductCategory::with('translations')->get()
                            ->mapWithKeys(function ($c) use ($locale) {
                                $name = $c->translateOrDefault($locale)?->name ?? $c->translate('en')?->name ?? "Category #{$c->id}";
                                return [$c->id => $name];
                            })
                            ->sortBy(fn ($name) => mb_strtolower($name))
                            ->toArray();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('عرض'),
                Tables\Actions\EditAction::make()->slideOver()->modalWidth('7xl'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label(__('Add Product'))->slideOver()->modalWidth('7xl'),
            ])
            ->defaultSort('id', 'desc');
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
                            ->state(function (Product $record) use ($locale) {
                                $cat = $record->category?->loadMissing('parent', 'translations', 'parent.translations');
                                if (! $cat) {
                                    return '—';
                                }
                                $parentName = $cat->parent ? localized_name($cat->parent, 'name') : null;
                                $childName = localized_name($cat, 'name');

                                return $parentName ? "{$parentName} ← {$childName}" : $childName;
                            })
                            ->badge()
                            ->color('info'),

                        Info\TextEntry::make('classification')
                            ->label('التصنيف')
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
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view'   => Pages\ViewProduct::route('/{record}'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}

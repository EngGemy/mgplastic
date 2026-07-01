<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\ProductVariantResource\Pages;
use App\Models\ProductVariant;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductVariantResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = ProductVariant::class;
    protected static ?string $navigationIcon  = 'heroicon-o-adjustments-horizontal';
    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string { return 'المنتجات'; }
    public static function getNavigationLabel(): string { return __('Product Variants'); }
    public static function getModelLabel(): string { return __('Product Variant'); }
    public static function getPluralModelLabel(): string { return __('Product Variants'); }

    /** Eager-load product + translations for fast, null-safe lookups */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['product.translations']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Base'))
                ->columns(3)
                ->schema([
                    // Locale-aware product selector (no relationship titleAttr)
                    Forms\Components\Select::make('product_id')
                        ->label(__('Product'))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->options(function () {
                            $locale = app()->getLocale();
                            return Product::with('translations')
                                ->latest('id')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(function ($p) use ($locale) {
                                    $name = optional($p->translate($locale))->name
                                        ?? optional($p->translate('en'))->name
                                        ?? "Product #{$p->id}";
                                    return [$p->id => $name];
                                })->toArray();
                        })
                        ->getOptionLabelUsing(function ($value) {
                            if (!$value) return null;
                            $locale = app()->getLocale();
                            $p = Product::with('translations')->find($value);
                            return $p
                                ? (optional($p->translate($locale))->name
                                    ?? optional($p->translate('en'))->name
                                    ?? "Product #{$p->id}")
                                : null;
                        })
                        ->getSearchResultsUsing(function (string $search) {
                            $locale = app()->getLocale();
                            $products = Product::with('translations')
                                ->whereHas('translations', fn ($q) =>
                                $q->where('name', 'like', "%{$search}%"))
                                ->limit(50)
                                ->get();

                            return $products->mapWithKeys(function ($p) use ($locale) {
                                $name = optional($p->translate($locale))->name
                                    ?? optional($p->translate('en'))->name
                                    ?? "Product #{$p->id}";
                                return [$p->id => $name];
                            })->toArray();
                        })
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('catalog_code')
                        ->label(__('Catalog Code'))
                        ->maxLength(50)
                        ->columnSpan(1),

                    Forms\Components\Select::make('pressure_class')
                        ->label(__('Pressure Class'))
                        ->options([
                            'Pn4' => 'Pn4', 'Pn6' => 'Pn6', 'Pn10' => 'Pn10',
                            'Pn12.5' => 'Pn12.5', 'Pn16' => 'Pn16', 'Pn20' => 'Pn20',
                        ])
                        ->native(false)
                        ->searchable()
                        ->placeholder('—')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('weight_kg_per_m')
                        ->label(__('Weight (kg/m)'))
                        ->numeric()->step('0.0001')->minValue(0)
                        ->columnSpan(1),
                ]),

            Forms\Components\Section::make(__('Dimensions (mm)'))
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('outer_diameter_mm')->label(__('Outer Ø (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('wall_thickness_mm')->label(__('Wall thickness (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('insertion_depth_mm')->label(__('Insertion depth (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('width_w_mm')->label(__('W (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('height_l_mm')->label(__('L (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('depth_h_mm')->label(__('H (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('depth_h1_mm')->label(__('H1 (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('depth_h2_mm')->label(__('H2 (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('depth_h3_mm')->label(__('H3 (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('d1_mm')->label(__('d1 (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('d2_mm')->label(__('d2 (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('d3_mm')->label(__('d3 (mm)'))->numeric()->step('0.01')->minValue(0),
                    Forms\Components\TextInput::make('d4_mm')->label(__('d4 (mm)'))->numeric()->step('0.01')->minValue(0),
                ]),

            Forms\Components\Section::make(__('Extra (JSON)'))
                ->schema([
                    Forms\Components\KeyValue::make('extra')
                        ->label(__('Extra (Key/Value)'))
                        ->addButtonLabel(__('Add attribute'))
                        ->keyLabel(__('Key'))
                        ->valueLabel(__('Value'))
                        ->reorderable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Show translated product name
                Tables\Columns\TextColumn::make('product_name')
                    ->label(__('Product'))
                    ->state(function ($record) {
                        $locale = app()->getLocale();
                        return optional($record?->product?->translate($locale))->name
                            ?? optional($record?->product?->translate('en'))->name
                            ?? "Product #{$record?->product_id}";
                    })
                    ->wrap()
                    ->sortable(false)
                    ->searchable(false),

                Tables\Columns\TextColumn::make('catalog_code')
                    ->label(__('Catalog'))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('outer_diameter_mm')
                    ->label(__('Ø (mm)'))
                    ->numeric(2)->suffix(' mm')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('wall_thickness_mm')
                    ->label(__('t (mm)'))
                    ->numeric(2)->suffix(' mm')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('insertion_depth_mm')
                    ->label(__('Insert (mm)'))
                    ->numeric(2)->suffix(' mm')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('weight_kg_per_m')
                    ->label(__('Weight'))
                    ->numeric(4)->suffix(' kg/m')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('pressure_class')
                    ->label(__('PN'))
                    ->colors([
                        'primary',
                        'success' => fn ($state) => in_array($state, ['Pn16','Pn20']),
                        'warning' => fn ($state) => in_array($state, ['Pn10','Pn12.5']),
                        'gray'    => fn ($state) => in_array($state, ['Pn4','Pn6', null, '']),
                    ])
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('product_id')
            ->filters([
                Tables\Filters\SelectFilter::make('product_id')
                    ->label(__('Product'))
                    ->options(function () {
                        $locale = app()->getLocale();
                        return Product::with('translations')
                            ->latest('id')
                            ->limit(200)
                            ->get()
                            ->mapWithKeys(function ($p) use ($locale) {
                                $name = optional($p->translate($locale))->name
                                    ?? optional($p->translate('en'))->name
                                    ?? "Product #{$p->id}";
                                return [$p->id => $name];
                            })->toArray();
                    })
                    ->searchable(),

                Tables\Filters\SelectFilter::make('pressure_class')
                    ->label(__('PN'))
                    ->options([
                        'Pn4' => 'Pn4','Pn6' => 'Pn6','Pn10' => 'Pn10',
                        'Pn12.5' => 'Pn12.5','Pn16' => 'Pn16','Pn20' => 'Pn20',
                    ]),

                Tables\Filters\Filter::make('diameter_range')
                    ->label(__('Diameter range'))
                    ->form([
                        Forms\Components\TextInput::make('min_od')->numeric()->label(__('Min Ø (mm)')),
                        Forms\Components\TextInput::make('max_od')->numeric()->label(__('Max Ø (mm)')),
                    ])
                    ->query(fn ($query, array $data) =>
                    $query
                        ->when($data['min_od'] ?? null, fn ($q, $v) => $q->where('outer_diameter_mm', '>=', $v))
                        ->when($data['max_od'] ?? null, fn ($q, $v) => $q->where('outer_diameter_mm', '<=', $v))
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProductVariants::route('/'),
            'create' => Pages\CreateProductVariant::route('/create'),
            'edit'   => Pages\EditProductVariant::route('/{record}/edit'),
        ];
    }
}

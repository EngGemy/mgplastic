<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\ProductCategoryResource\Pages;
use App\Models\ProductCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ProductCategoryResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = ProductCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string { return 'المنتجات'; }
    public static function getNavigationLabel(): string { return __('Product Categories'); }
    public static function getModelLabel(): string { return __('Product Category'); }
    public static function getPluralModelLabel(): string { return __('Product Categories'); }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Category'))
                ->icon('heroicon-o-squares-2x2')
                ->collapsible()
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('slug')
                        ->label(__('Slug'))
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(190)
                        ->columnSpan(4),

                    Forms\Components\Select::make('parent_id')
                        ->label(__('Parent Category'))
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->placeholder(__('Root (no parent)'))
                        ->relationship(name: 'parent', titleAttribute: 'name')
                        ->getOptionLabelFromRecordUsing(fn (ProductCategory $rec) => $rec->name ?? $rec->slug)
                        ->rules([
                            'nullable',
                            Rule::exists('product_categories', 'id'),
                        ])
                        ->helperText(__('Leave empty to make this a root category.'))
                        ->columnSpan(4),

                    Forms\Components\FileUpload::make('image')
                        ->label(__('Image'))
                        ->disk('public')
                        ->directory('categories')
                        ->image()
                        ->imageEditor()
                        ->openable()
                        ->downloadable()
                        ->columnSpan(4),

                    Forms\Components\Tabs::make(__('Translations'))
                        ->columnSpanFull()
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('English')->schema([
                                Forms\Components\TextInput::make('name_en')
                                    ->label(__('Name (EN)'))
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('description_en')
                                    ->label(__('Description (EN)'))
                                    ->rows(5)
                                    ->columnSpanFull(),
                            ]),
                            Forms\Components\Tabs\Tab::make('العربية')->schema([
                                Forms\Components\TextInput::make('name_ar')
                                    ->label(__('Name (AR)'))
                                    ->required()
                                    ->maxLength(255)
                                    ->extraAttributes(['dir' => 'rtl']),
                                Forms\Components\Textarea::make('description_ar')
                                    ->label(__('Description (AR)'))
                                    ->rows(5)
                                    ->columnSpanFull()
                                    ->extraAttributes(['dir' => 'rtl']),
                            ]),
                        ]),
                ]),
        ])->columns(12);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('slug')
            ->heading(__('Product Categories'))
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('Image'))
                    ->disk('public')
                    ->square()
                    ->size(56),

                Tables\Columns\TextColumn::make('slug')
                    ->label(__('Slug'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label(__('Parent'))
                    ->formatStateUsing(fn ($state) => $state ?: __('Root'))
                    ->badge()
                    ->toggleable(),

                // اسم بحسب اللغة الحالية (أو يظهر الـ slug لو مفيش ترجمة)
                Tables\Columns\TextColumn::make('name_current')
                    ->label(__('Name'))
                    ->state(fn (ProductCategory $record) => $record->name ?? $record->slug)
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->where('slug', 'like', "%{$search}%")
                            ->orWhereHas('translations', fn ($q) =>
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('description', 'like', "%{$search}%")
                            );
                    })
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('products_count')
                    ->label(__('Products'))
                    ->counts('products')
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('parent_id')
                    ->label(__('Parent'))
                    ->relationship('parent', 'name')
                    ->getOptionLabelFromRecordUsing(fn (ProductCategory $rec) => $rec->name ?? $rec->slug),

                Tables\Filters\TernaryFilter::make('is_root')
                    ->label(__('Root only'))
                    ->nullable()
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('parent_id'),
                        false: fn (Builder $q) => $q->whereNotNull('parent_id'),
                        blank: fn (Builder $q) => $q
                    ),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->requiresConfirmation()
                        ->disabled(fn (Model $record) => $record->children()->exists())
                        ->tooltip(fn (Model $record) =>
                        $record->children()->exists()
                            ? __('Cannot delete a category that has children.')
                            : null
                        ),
                ])->icon('heroicon-o-ellipsis-horizontal'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-tag')
            ->emptyStateHeading(__('No categories yet'))
            ->emptyStateDescription(__('Create your first product category to get started.'))
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label(__('Create Category')),
            ]);
    }

    /** التوقيع الصحيح */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('parent')
            ->withCount('products'); // يعتمد على FK الصحيح في علاقة products() بالموديل
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProductCategories::route('/'),
            'create' => Pages\CreateProductCategory::route('/create'),
            'edit'   => Pages\EditProductCategory::route('/{record}/edit'),
        ];
    }
}

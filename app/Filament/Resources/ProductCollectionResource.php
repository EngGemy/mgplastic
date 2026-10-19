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
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('العنوان')
                ->required()
                ->maxLength(200)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, Forms\Set $set, ?ProductCollection $record) {
                    if ($record?->exists) {
                        return;
                    }
                    $set('slug', \Illuminate\Support\Str::slug((string) $state) ?: null);
                }),

            Forms\Components\TextInput::make('slug')
                ->label('المعرّف (slug)')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(80),

            Forms\Components\TextInput::make('subtitle')
                ->label('عنوان فرعي')
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('description')
                ->label('الوصف')
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\Select::make('kind')
                ->label('النوع')
                ->options([
                    'featured' => 'منتجات مميزة',
                    'new' => 'منتجات حديثة',
                    'important' => 'أهم المنتجات',
                    'custom' => 'مجموعة مخصّصة',
                ])
                ->required()
                ->native(false)
                ->default('custom'),

            Forms\Components\FileUpload::make('cover_image')
                ->label('صورة الغلاف')
                ->disk('public')
                ->directory('product-collections')
                ->image()
                ->imageEditor(),

            Forms\Components\Select::make('products')
                ->label('المنتجات')
                ->relationship('products', 'id')
                ->multiple()
                ->searchable()
                ->preload()
                ->getOptionLabelFromRecordUsing(fn (Product $p) => localized_name($p, 'name', "منتج #{$p->id}"))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('sort_order')
                ->label('الترتيب')
                ->numeric()
                ->default(0),

            Forms\Components\Toggle::make('is_active')
                ->label('نشط')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover_image')
                    ->label('غلاف')
                    ->disk('public')
                    ->square()
                    ->size(56),
                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('kind')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state, ProductCollection $r) => $r->kindLabel()),
                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('منتجات'),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('ترتيب')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
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

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
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('العنوان')
                ->required()
                ->maxLength(200),

            Forms\Components\TextInput::make('subtitle')
                ->label('عنوان فرعي')
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\FileUpload::make('image_path')
                ->label('صورة البانر')
                ->disk('public')
                ->directory('promo-banners')
                ->image()
                ->imageEditor()
                ->required()
                ->columnSpanFull(),

            Forms\Components\Select::make('section')
                ->label('مكان العرض في التطبيق')
                ->options([
                    PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES => 'بين السباكين والمتاجر',
                ])
                ->required()
                ->native(false)
                ->default(PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES),

            Forms\Components\Select::make('link_type')
                ->label('نوع الرابط')
                ->options([
                    'none' => 'بدون رابط',
                    'url' => 'رابط خارجي',
                    'product_collection' => 'مجموعة منتجات',
                    'product_promotion' => 'إعلان منتج MG',
                    'product' => 'منتج واحد',
                ])
                ->required()
                ->native(false)
                ->live()
                ->default('none'),

            Forms\Components\TextInput::make('link_url')
                ->label('الرابط')
                ->url()
                ->visible(fn (Get $get) => $get('link_type') === 'url')
                ->columnSpanFull(),

            Forms\Components\Select::make('link_id')
                ->label('الهدف')
                ->searchable()
                ->options(function (Get $get) {
                    return match ($get('link_type')) {
                        'product_collection' => ProductCollection::query()->orderBy('title')->pluck('title', 'id'),
                        'product_promotion' => ProductPromotion::query()->orderBy('title')->pluck('title', 'id'),
                        'product' => Product::query()
                            ->with('translations')
                            ->limit(400)
                            ->get()
                            ->mapWithKeys(fn (Product $p) => [$p->id => localized_name($p, 'name', "#{$p->id}")]),
                        default => [],
                    };
                })
                ->visible(fn (Get $get) => in_array($get('link_type'), [
                    'product_collection',
                    'product_promotion',
                    'product',
                ], true)),

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
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('صورة')
                    ->disk('public')
                    ->square()
                    ->size(72),
                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('section')
                    ->label('السكنشن')
                    ->formatStateUsing(fn (string $state) => $state === PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES
                        ? 'بين السباكين والمتاجر'
                        : $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('link_type')
                    ->label('الرابط')
                    ->badge(),
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
            'index' => Pages\ListPromoBanners::route('/'),
            'create' => Pages\CreatePromoBanner::route('/create'),
            'edit' => Pages\EditPromoBanner::route('/{record}/edit'),
        ];
    }
}

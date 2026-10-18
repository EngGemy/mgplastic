<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\ProductPromotionResource\Pages;
use App\Models\Product;
use App\Models\ProductPromotion;
use Filament\Forms;
use Filament\Forms\Form;
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
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('العنوان')
                ->required()
                ->maxLength(200)
                ->columnSpanFull(),

            Forms\Components\TextInput::make('subtitle')
                ->label('عنوان فرعي')
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('body')
                ->label('الوصف / المقارنة النصية')
                ->rows(4)
                ->columnSpanFull(),

            Forms\Components\Select::make('media_type')
                ->label('نوع الوسائط')
                ->options([
                    'image' => 'صورة',
                    'video' => 'فيديو',
                    'comparison' => 'مقارنة (صورتان)',
                ])
                ->required()
                ->native(false)
                ->live()
                ->default('image'),

            Forms\Components\FileUpload::make('image_path')
                ->label('الصورة الرئيسية')
                ->disk('public')
                ->directory('product-promotions')
                ->image()
                ->imageEditor()
                ->visible(fn (Forms\Get $get) => in_array($get('media_type'), ['image', 'comparison', 'video'], true)),

            Forms\Components\FileUpload::make('comparison_image_path')
                ->label('صورة المقارنة (قبل/بعد أو منافس)')
                ->disk('public')
                ->directory('product-promotions')
                ->image()
                ->visible(fn (Forms\Get $get) => $get('media_type') === 'comparison'),

            Forms\Components\TextInput::make('video_url')
                ->label('رابط الفيديو')
                ->url()
                ->visible(fn (Forms\Get $get) => $get('media_type') === 'video')
                ->columnSpanFull(),

            Forms\Components\Select::make('product_id')
                ->label('منتج MG المرتبط')
                ->searchable()
                ->preload()
                ->options(fn () => Product::query()
                    ->with('translations')
                    ->orderBy('id')
                    ->limit(500)
                    ->get()
                    ->mapWithKeys(fn (Product $p) => [
                        $p->id => localized_name($p, 'name', "منتج #{$p->id}"),
                    ]))
                ->nullable(),

            Forms\Components\TextInput::make('product_url')
                ->label('رابط خارجي للمنتج')
                ->url()
                ->helperText('اختياري — إن وُجد يُفضَّل على رابط المنتج الداخلي'),

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
                    ->size(64),

                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('media_type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'video' => 'فيديو',
                        'comparison' => 'مقارنة',
                        default => 'صورة',
                    }),

                Tables\Columns\TextColumn::make('product_id')
                    ->label('المنتج')
                    ->formatStateUsing(fn ($state, ProductPromotion $record) => $record->product
                        ? localized_name($record->product, 'name')
                        : '—'),

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
            'index' => Pages\ListProductPromotions::route('/'),
            'create' => Pages\CreateProductPromotion::route('/create'),
            'edit' => Pages\EditProductPromotion::route('/{record}/edit'),
        ];
    }
}

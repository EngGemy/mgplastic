<?php

namespace App\Filament\RelationManagers;

use App\Models\Product;
use App\Models\SocialLink;
use App\Models\StoreMedia;
use App\Services\VideoThumbnailService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StoreMediaRelationManager extends RelationManager
{
    protected static string $relationship = 'storeMedia';

    protected static ?string $title = 'كتالوج المتجر';

    protected static ?string $modelLabel = 'وسائط';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('kind')
                ->label('النوع')
                ->options([
                    'banner' => 'بانر / سلايدر',
                    'gallery' => 'صورة معرض',
                    'product_image' => 'صورة منتج في الكتالوج',
                    'video' => 'فيديو',
                ])
                ->required()
                ->live()
                ->native(false),

            Forms\Components\Select::make('product_id')
                ->label('المنتج')
                ->options(fn () => Product::query()
                    ->with('translations')
                    ->orderBy('id')
                    ->get()
                    ->mapWithKeys(fn ($p) => [$p->id => localized_name($p, 'name', "منتج #{$p->id}")]))
                ->searchable()
                ->visible(fn (Get $get) => $get('kind') === 'product_image')
                ->required(fn (Get $get) => $get('kind') === 'product_image'),

            Forms\Components\FileUpload::make('file_path')
                ->label('الملف')
                ->disk('public')
                ->directory(fn (Get $get) => match ($get('kind')) {
                    'video' => 'store_media/videos',
                    'banner' => 'store_media/banners',
                    'product_image' => 'store_media/products',
                    default => 'store_media/gallery',
                })
                ->acceptedFileTypes(fn (Get $get) => $get('kind') === 'video'
                    ? ['video/mp4', 'video/webm', 'video/quicktime']
                    : ['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(fn (Get $get) => $get('kind') === 'video' ? 512000 : 10240)
                ->image(fn (Get $get) => $get('kind') !== 'video')
                ->imageEditor(fn (Get $get) => $get('kind') !== 'video')
                ->required()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('title')
                ->label('عنوان (اختياري)')
                ->maxLength(255),

            Forms\Components\TextInput::make('sort_order')
                ->label('الترتيب')
                ->numeric()
                ->default(0)
                ->minValue(0),

            Forms\Components\Toggle::make('is_active')
                ->label('ظاهر في الكتالوج')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_path')
                    ->label('معاينة')
                    ->disk('public')
                    ->defaultImageUrl(fn (StoreMedia $record) => $record->kind === 'video' ? null : $record->url)
                    ->getStateUsing(fn (StoreMedia $record) => $record->thumbnail_path ?: ($record->kind !== 'video' ? $record->file_path : null))
                    ->size(56),

                Tables\Columns\TextColumn::make('kind')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'banner' => 'بانر',
                        'gallery' => 'معرض',
                        'product_image' => 'منتج',
                        'video' => 'فيديو',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'video' => 'danger',
                        'banner' => 'primary',
                        'product_image' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->default('—')
                    ->limit(30),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('المنتج')
                    ->default('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('ترتيب')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة وسائط')
                    ->after(function (StoreMedia $record) {
                        if ($record->kind === 'video' && $record->file_path && ! $record->thumbnail_path) {
                            $thumb = app(VideoThumbnailService::class)->generate(
                                $record->file_path,
                                'store_media/thumbnails'
                            );
                            if ($thumb) {
                                $record->update(['thumbnail_path' => $thumb]);
                            }
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('لا توجد صور أو فيديوهات')
            ->emptyStateDescription('أضف بانرات، صور منتجات، أو فيديوهات لكتالوج متجرك');
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\ProductTagImageResource\Pages;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductTagImageResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = ProductTag::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'tag-images';

    public static function getNavigationGroup(): ?string
    {
        return 'المنتجات';
    }

    public static function getNavigationLabel(): string
    {
        return 'صوره التاجات';
    }

    public static function getModelLabel(): string
    {
        return 'صورة تاج';
    }

    public static function getPluralModelLabel(): string
    {
        return 'صوره التاجات';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الوسم')
                ->description('التاجات تأتي من المنتجات. ارفع هنا صورة الأكورديون بجانب المقاس في التطبيق.')
                ->schema([
                    Forms\Components\Placeholder::make('edit_tag_info')
                        ->label('التاج')
                        ->content(function (Forms\Components\Placeholder $component): string {
                            $record = $component->getRecord();
                            if (! $record instanceof ProductTag) {
                                return '—';
                            }
                            try {
                                $cat = $record->category
                                    ? $record->category->normalizedDisplayName()
                                    : 'بدون تصنيف';
                            } catch (\Throwable) {
                                $cat = 'بدون تصنيف';
                            }

                            return trim((string) $record->name).' · '.$cat;
                        })
                        ->visibleOn('edit'),

                    Forms\Components\Select::make('product_category_id')
                        ->label('التصنيف')
                        ->options(fn () => ProductCategory::query()
                            ->with('translations')
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(fn (ProductCategory $c) => [
                                $c->id => $c->normalizedDisplayName().' (#'.$c->id.')',
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->visibleOn('create'),

                    Forms\Components\TextInput::make('name')
                        ->label('اسم التاج / المقاس')
                        ->required()
                        ->maxLength(80)
                        ->helperText('مثال: 1 بوصه · 110 مم')
                        ->visibleOn('create'),

                    Forms\Components\FileUpload::make('image')
                        ->label('صورة التاج')
                        ->disk('public')
                        ->directory('tags')
                        ->image()
                        ->imageEditor()
                        ->openable()
                        ->downloadable()
                        ->nullable()
                        ->helperText('صورة مربعة للتطبيق. عند الحفظ تُطبَّق على كل تاج بنفس الاسم.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('الصورة')
                    ->disk('public')
                    ->circular()
                    ->size(48)
                    ->defaultImageUrl(null),

                Tables\Columns\TextColumn::make('name')
                    ->label('التاج')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('category_name')
                    ->label('التصنيف')
                    ->state(function (ProductTag $record): string {
                        try {
                            return $record->category
                                ? $record->category->normalizedDisplayName()
                                : '—';
                        } catch (\Throwable) {
                            return '—';
                        }
                    })
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('منتجات')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\IconColumn::make('has_image')
                    ->label('لها صورة')
                    ->boolean()
                    ->getStateUsing(fn (ProductTag $record) => filled($record->image)),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('product_category_id')
                    ->label('التصنيف')
                    ->options(fn () => ProductCategory::query()
                        ->with('translations')
                        ->orderBy('id')
                        ->get()
                        ->mapWithKeys(fn (ProductCategory $c) => [
                            $c->id => $c->normalizedDisplayName(),
                        ])
                        ->all())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('has_image')
                    ->label('لها صورة')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('image')->where('image', '!=', ''),
                        false: fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->whereNull('image')->orWhere('image', '')),
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تغيير الصورة'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('لا توجد تاجات بعد')
            ->emptyStateDescription('أضف وسومًا على المنتجات أولًا، ثم ارجع هنا لرفع صور الأكورديون.')
            ->emptyStateIcon('heroicon-o-tag');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['category.translations'])
            ->withCount('products');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductTagImages::route('/'),
            'create' => Pages\CreateProductTagImage::route('/create'),
            'edit' => Pages\EditProductTagImage::route('/{record}/edit'),
        ];
    }

    /** Copy image to every tag that shares the same name (case-insensitive). */
    public static function syncImageToSameNameTags(ProductTag $tag): int
    {
        $name = mb_strtolower(trim((string) $tag->name));
        if ($name === '') {
            return 0;
        }

        return (int) ProductTag::query()
            ->whereRaw('LOWER(name) = ?', [$name])
            ->where('id', '!=', $tag->id)
            ->update(['image' => $tag->image]);
    }
}

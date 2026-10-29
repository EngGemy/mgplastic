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
                ->description('التاجات تأتي من المنتجات (حقل الوسوم). هنا ترفع صورة الأكورديون اللي تظهر في التطبيق بجانب المقاس.')
                ->schema([
                    Forms\Components\Select::make('product_category_id')
                        ->label('التصنيف')
                        ->relationship(
                            name: 'category',
                            titleAttribute: 'id',
                            modifyQueryUsing: fn (Builder $q) => $q->with('translations')->orderBy('id'),
                        )
                        ->getOptionLabelFromRecordUsing(
                            fn (ProductCategory $c) => $c->normalizedDisplayName().' (#'.$c->id.')'
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated()
                        ->helperText('عند التعديل لا يتغيّر التصنيف — غيّر الصورة فقط.'),

                    Forms\Components\TextInput::make('name')
                        ->label('اسم التاج / المقاس')
                        ->required()
                        ->maxLength(80)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated()
                        ->helperText('مثال: 1 بوصه · 110 مم — نفس الاسم المستخدم على المنتج.'),

                    Forms\Components\FileUpload::make('image')
                        ->label('صورة التاج')
                        ->disk('public')
                        ->directory('tags')
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['1:1', '4:3', null])
                        ->openable()
                        ->downloadable()
                        ->helperText('صورة مربعة تظهر في أقسام المقاسات داخل التطبيق. عند الحفظ تُطبَّق على كل تاج بنفس الاسم.')
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
                    ->size(48),

                Tables\Columns\TextColumn::make('name')
                    ->label('التاج')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('category_name')
                    ->label('التصنيف')
                    ->state(fn (ProductTag $record) => $record->category
                        ? $record->category->normalizedDisplayName()
                        : '—')
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
                    ->state(fn (ProductTag $record) => filled($record->image)),

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
                    ->relationship('category', 'id')
                    ->getOptionLabelFromRecordUsing(
                        fn (ProductCategory $c) => $c->normalizedDisplayName()
                    )
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('image')
                    ->label('لها صورة')
                    ->nullable()
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

        return ProductTag::query()
            ->whereRaw('LOWER(name) = ?', [$name])
            ->where('id', '!=', $tag->id)
            ->update(['image' => $tag->image]);
    }
}

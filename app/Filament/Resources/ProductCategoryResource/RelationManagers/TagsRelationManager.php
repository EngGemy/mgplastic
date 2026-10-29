<?php

namespace App\Filament\Resources\ProductCategoryResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $title = 'وسوم المقاسات (أيقونات الأكورديون)';

    protected static ?string $modelLabel = 'وسم';

    protected static ?string $pluralModelLabel = 'الوسوم';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('اسم الوسم / المقاس')
                ->required()
                ->maxLength(80)
                ->helperText('مثال: 1 بوصه · 110 مم — يظهر كعنوان قسم في التطبيق'),

            Forms\Components\FileUpload::make('image')
                ->label('أيقونة القسم')
                ->disk('public')
                ->directory('tags')
                ->image()
                ->imageEditor()
                ->openable()
                ->downloadable()
                ->helperText('صورة مربعة صغيرة تظهر بجانب «1 بوصه» في قائمة الملحقات. اتركها فارغة ليستخدم التطبيق صورة افتراضية.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('أيقونة')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(null),
                Tables\Columns\TextColumn::make('name')
                    ->label('الوسم')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('منتجات')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة وسم + أيقونة'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل الأيقونة'),
                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->visible(fn ($record) => $record->products()->count() === 0),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('لا توجد وسوم بعد')
            ->emptyStateDescription('أضف وسوم المقاسات (1 بوصه، 2 بوصه…) وارفع أيقونة لكل قسم.')
            ->emptyStateIcon('heroicon-o-tag');
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\BlogCategoryResource\Pages;
use App\Models\BlogCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BlogCategoryResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = BlogCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'تصنيفات المقالات';
    }

    public static function getModelLabel(): string
    {
        return 'تصنيف مقال';
    }

    public static function getPluralModelLabel(): string
    {
        return 'تصنيفات المقالات';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('التصنيف')
                ->description('يُستخدم لتجميع مقالات المدونة.')
                ->icon('heroicon-o-tag')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('اسم التصنيف')
                        ->required()
                        ->maxLength(255)
                        ->extraAttributes(['dir' => 'rtl']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('التصنيف')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('أُنشئ')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogCategories::route('/'),
            'create' => Pages\CreateBlogCategory::route('/create'),
            'edit' => Pages\EditBlogCategory::route('/{record}/edit'),
        ];
    }
}

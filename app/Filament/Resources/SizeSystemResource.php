<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\SizeSystemResource\Pages;
use App\Filament\Resources\SizeSystemResource\RelationManagers\SizesRelationManager;
use App\Models\SizeSystem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SizeSystemResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = SizeSystem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return 'الإعدادات';
    }

    public static function getNavigationLabel(): string
    {
        return 'أنظمة المقاسات';
    }

    public static function getModelLabel(): string
    {
        return 'نظام مقاسات';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أنظمة المقاسات';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('sizes');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('نظام المقاسات')
                ->description('عرّف نظام مقاسات (مثل US / EU) ثم أضف المقاسات من تبويب المقاسات.')
                ->icon('heroicon-o-cog-6-tooth')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('الكود')
                        ->helperText('قصير وفريد، مثل us أو eu')
                        ->required()
                        ->alphaDash()
                        ->maxLength(8)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('name_ar')
                        ->label('الاسم بالعربية')
                        ->maxLength(50)
                        ->extraAttributes(['dir' => 'rtl']),

                    Forms\Components\TextInput::make('name_en')
                        ->label('الاسم بالإنجليزية')
                        ->required()
                        ->maxLength(50),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('الكود')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name_ar')
                    ->label('العربية')
                    ->wrap()
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('name_en')
                    ->label('English')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('sizes_count')
                    ->label('المقاسات')
                    ->counts('sizes')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل')->slideOver()->modalWidth('3xl'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة نظام')
                    ->slideOver()
                    ->modalWidth('3xl'),
            ])
            ->defaultSort('id', 'desc')
            ->striped();
    }

    public static function getRelations(): array
    {
        return [
            SizesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSizeSystems::route('/'),
            'create' => Pages\CreateSizeSystem::route('/create'),
            'edit' => Pages\EditSizeSystem::route('/{record}/edit'),
        ];
    }
}

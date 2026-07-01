<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\EventCategoryResource\Pages;
use App\Models\EventCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EventCategoryResource extends Resource
{
    use AdminOnlyResource;
    protected static ?string $model = EventCategory::class;
    protected static ?string $navigationIcon  = 'heroicon-o-rectangle-group';
    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string { return 'المحتوى'; }
    public static function getNavigationLabel(): string { return 'تصنيفات الفعاليات'; }
    public static function getModelLabel(): string { return 'تصنيف فعالية'; }
    public static function getPluralModelLabel(): string { return 'تصنيفات الفعاليات'; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name_en')
                ->label(__('Name (EN)'))
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('name_ar')
                ->label(__('Name (AR)'))
                ->required()
                ->maxLength(255)
                ->extraAttributes(['dir' => 'rtl']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_en')
                    ->label(__('Name (EN)'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name_ar')
                    ->label(__('Name (AR)'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListEventCategories::route('/'),
            'create' => Pages\CreateEventCategory::route('/create'),
            'edit'   => Pages\EditEventCategory::route('/{record}/edit'),
        ];
    }
}

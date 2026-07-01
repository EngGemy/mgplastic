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

// app/Filament/Resources/SizeSystemResource.php
    protected static ?string $navigationIcon = 'heroicon-o-cog'; // or 'heroicon-o-collection'
    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Size Systems';
    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('Size System');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Size Systems');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('sizes');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('System'))
                ->description(__('Define a size system (e.g. US / EU).'))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label(__('Code'))
                        ->helperText(__('Short unique code, e.g. "us" or "eu".'))
                        ->required()
                        ->alphaDash()
                        ->maxLength(8)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('name_en')
                        ->label(__('Name (EN)'))
                        ->required()
                        ->maxLength(50),

                    Forms\Components\TextInput::make('name_ar')
                        ->label(__('Name (AR)'))
                        ->maxLength(50)
                        ->extraAttributes(['dir' => 'rtl']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('Code'))
                    ->badge()
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name_en')
                    ->label(__('Name (EN)'))
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name_ar')
                    ->label(__('Name (AR)'))
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sizes_count')
                    ->label(__('Sizes'))
                    ->counts('sizes')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make()->slideOver()->modalWidth('3xl'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Add System'))
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
            'index'  => Pages\ListSizeSystems::route('/'),
            'create' => Pages\CreateSizeSystem::route('/create'),
            'edit'   => Pages\EditSizeSystem::route('/{record}/edit'),
        ];
    }
}

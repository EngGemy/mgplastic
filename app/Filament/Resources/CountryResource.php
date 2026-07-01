<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\CountryResource\Pages;
use App\Filament\Resources\CountryResource\RelationManagers\CitiesRelationManager;
use App\Models\Country;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CountryResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = Country::class;
    protected static ?string $navigationIcon  = 'heroicon-o-globe-alt';
    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string { return 'الإعدادات'; }
    public static function getNavigationLabel(): string { return 'الدول'; }
    public static function getModelLabel(): string { return 'دولة'; }
    public static function getPluralModelLabel(): string { return 'الدول'; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Country'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name_en')
                        ->label(__('Name (EN)'))
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('name_ar')
                        ->label(__('Name (AR)'))
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
                Tables\Columns\TextColumn::make('name_en')
                    ->label(__('Name (EN)'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name_ar')
                    ->label(__('Name (AR)'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cities_count')
                    ->label(__('Cities'))
                    ->counts('cities')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCountries::route('/'),
            'create' => Pages\CreateCountry::route('/create'),
            'edit'   => Pages\EditCountry::route('/{record}/edit'),
        ];
    }
}

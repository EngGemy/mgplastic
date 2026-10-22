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
            Forms\Components\Section::make('الدولة')
                ->icon('heroicon-o-globe-alt')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name_ar')
                        ->label('الاسم بالعربية')
                        ->required()
                        ->maxLength(255)
                        ->extraAttributes(['dir' => 'rtl']),

                    Forms\Components\TextInput::make('name_en')
                        ->label('الاسم بالإنجليزية')
                        ->required()
                        ->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_ar')
                    ->label('العربية')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name_en')
                    ->label('English')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cities_count')
                    ->label('المدن')
                    ->counts('cities')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
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

<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnlyResource;
use App\Filament\Resources\CityResource\Pages;
use App\Models\City;
use App\Models\Country;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class CityResource extends Resource
{
    use AdminOnlyResource;

    protected static ?string $model = City::class;
    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string { return 'الإعدادات'; }
    public static function getNavigationLabel(): string { return 'المدن'; }
    public static function getModelLabel(): string { return 'مدينة'; }
    public static function getPluralModelLabel(): string { return 'المدن'; }

    /** ✅ Safe base query: no $record usage required later */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->leftJoin('countries', 'cities.country_id', '=', 'countries.id')
            ->select([
                'cities.*',
                'countries.name_en as country_name_en',
                'countries.name_ar as country_name_ar',
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('City'))
                ->columns(2)
                ->schema([
                    // Use an options array (no record callbacks)
                    Forms\Components\Select::make('country_id')
                        ->label(__('Country'))
                        ->options(fn () => Country::query()
                            ->orderBy('name_en')
                            ->get()
                            ->pluck(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en', 'id')
                            ->toArray()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\TextInput::make('name_en')
                        ->label(__('Name (EN)'))
                        ->required()
                        ->maxLength(255)
                        ->rule(function ($get, $record) {
                            return Rule::unique('cities', 'name_en')
                                ->where('country_id', (int) $get('country_id'))
                                ->ignore($record?->id);
                        }),

                    Forms\Components\TextInput::make('name_ar')
                        ->label(__('Name (AR)'))
                        ->required()
                        ->maxLength(255)
                        ->extraAttributes(['dir' => 'rtl'])
                        ->rule(function ($get, $record) {
                            return Rule::unique('cities', 'name_ar')
                                ->where('country_id', (int) $get('country_id'))
                                ->ignore($record?->id);
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // ✅ Pure state (string), no $record needed
                Tables\Columns\TextColumn::make('country_name_en')
                    ->label(__('Country'))
                    ->formatStateUsing(function ($state, $record) {
                        // $state = name_en; switch to Arabic if locale=ar
                        return app()->getLocale() === 'ar'
                            ? ($record->country_name_ar ?? $state)
                            : $state;
                    })
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name_en')
                    ->label(__('Name (EN)'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name_ar')
                    ->label(__('Name (AR)'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('country_id')
                    ->label(__('Country'))
                    ->options(fn () => Country::query()
                        ->orderBy('name_en')
                        ->get()
                        ->pluck(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en', 'id')
                        ->toArray()
                    )
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('country_id');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCities::route('/'),
            'create' => Pages\CreateCity::route('/create'),
            'edit'   => Pages\EditCity::route('/{record}/edit'),
        ];
    }
}

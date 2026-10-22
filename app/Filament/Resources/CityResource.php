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
            Forms\Components\Section::make('المدينة')
                ->icon('heroicon-o-map-pin')
                ->columns(12)
                ->schema([
                    Forms\Components\Select::make('country_id')
                        ->label('الدولة')
                        ->options(fn () => Country::query()
                            ->orderBy('name_ar')
                            ->pluck('name_ar', 'id')
                            ->toArray()
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('name_ar')
                        ->label('الاسم بالعربية')
                        ->required()
                        ->maxLength(255)
                        ->extraAttributes(['dir' => 'rtl'])
                        ->rule(function ($get, $record) {
                            return Rule::unique('cities', 'name_ar')
                                ->where('country_id', (int) $get('country_id'))
                                ->ignore($record?->id);
                        })
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('name_en')
                        ->label('الاسم بالإنجليزية')
                        ->required()
                        ->maxLength(255)
                        ->rule(function ($get, $record) {
                            return Rule::unique('cities', 'name_en')
                                ->where('country_id', (int) $get('country_id'))
                                ->ignore($record?->id);
                        })
                        ->columnSpan(4),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('country_name_en')
                    ->label('الدولة')
                    ->formatStateUsing(fn ($state, $record) => $record->country_name_ar ?? $state)
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name_ar')
                    ->label('العربية')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name_en')
                    ->label('English')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('country_id')
                    ->label('الدولة')
                    ->options(fn () => Country::query()
                        ->orderBy('name_ar')
                        ->pluck('name_ar', 'id')
                        ->toArray()
                    )
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
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

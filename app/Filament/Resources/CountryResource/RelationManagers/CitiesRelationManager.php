<?php

namespace App\Filament\Resources\CountryResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class CitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'cities';
    protected static ?string $title = 'Cities';
    protected static ?string $recordTitleAttribute = 'name_en';

    public function form(Form $form): Form
    {
        $countryId = $this->ownerRecord->id;

        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('name_en')
                    ->label('Name (EN)')
                    ->required()
                    ->maxLength(255)
                    ->rule(function ($record) use ($countryId) {
                        return Rule::unique('cities', 'name_en')
                            ->where('country_id', $countryId)
                            ->ignore($record?->id);
                    }),
                Forms\Components\TextInput::make('name_ar')
                    ->label('الاسم (AR)')
                    ->required()
                    ->maxLength(255)
                    ->extraAttributes(['dir' => 'rtl'])
                    ->rule(function ($record) use ($countryId) {
                        return Rule::unique('cities', 'name_ar')
                            ->where('country_id', $countryId)
                            ->ignore($record?->id);
                    }),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_en')
                    ->label('Name (EN)')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name_ar')
                    ->label('الاسم (AR)')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('name_en');
    }
}

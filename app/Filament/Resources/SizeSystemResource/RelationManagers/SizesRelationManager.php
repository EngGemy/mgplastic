<?php

namespace App\Filament\Resources\SizeSystemResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SizesRelationManager extends RelationManager
{
    protected static string $relationship = 'sizes';
    protected static ?string $recordTitleAttribute = 'label_en';

    /** ✅ Filament v3 signature */
    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Sizes');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(12)->schema([
                Forms\Components\TextInput::make('code')
                    ->label(__('Code'))
                    ->helperText(__('e.g. "XL", "42", "8.5". Must be unique within this system.'))
                    ->maxLength(32)
                    ->columnSpan(3)
                    ->rules([
                        fn () => function (string $attribute, $value, $fail) {
                            if ($value === null || $value === '') return;
                            $exists = $this->getOwnerRecord()
                                ->sizes()
                                ->where('code', $value)
                                ->when($this->getMountedTableActionRecord(), fn ($q) =>
                                $q->where('id', '!=', $this->getMountedTableActionRecord()->id)
                                )
                                ->exists();
                            if ($exists) $fail(__('This code already exists in this system.'));
                        },
                    ]),

                Forms\Components\TextInput::make('label_en')
                    ->label(__('Label (EN)'))
                    ->required()
                    ->maxLength(100)
                    ->columnSpan(4),

                Forms\Components\TextInput::make('label_ar')
                    ->label(__('Label (AR)'))
                    ->maxLength(100)
                    ->extraAttributes(['dir' => 'rtl'])
                    ->columnSpan(5),

                Forms\Components\TextInput::make('sort')
                    ->label(__('Sort'))
                    ->numeric()
                    ->default(0)
                    ->columnSpan(3),

                Forms\Components\KeyValue::make('meta')
                    ->label(__('Meta'))
                    ->columnSpan(9),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label_en')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('Code'))
                    ->badge()
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('label_en')
                    ->label(__('Label (EN)'))
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('label_ar')
                    ->label(__('Label (AR)'))
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sort')
                    ->label(__('Sort'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Add Size'))
                    ->slideOver()
                    ->modalWidth('4xl'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->slideOver()->modalWidth('4xl'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('sort', 'asc');
    }
}

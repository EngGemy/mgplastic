<?php

namespace App\Filament\RelationManagers;

use App\Models\SocialLink;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SocialLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'socialLinks';

    protected static ?string $title = 'روابط التواصل';

    protected static ?string $modelLabel = 'رابط';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('platform')
                ->label('المنصة')
                ->options(SocialLink::PLATFORMS)
                ->required()
                ->native(false)
                ->disableOptionWhen(function (?string $value, $record) {
                    if (! $value) {
                        return false;
                    }

                    return $this->getOwnerRecord()
                        ->socialLinks()
                        ->where('platform', $value)
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->exists();
                }),

            Forms\Components\TextInput::make('url')
                ->label('الرابط')
                ->url()
                ->required()
                ->maxLength(500)
                ->placeholder('https://'),

            Forms\Components\TextInput::make('sort_order')
                ->label('الترتيب')
                ->numeric()
                ->default(0)
                ->minValue(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('platform')
                    ->label('المنصة')
                    ->formatStateUsing(fn ($state) => SocialLink::PLATFORMS[$state] ?? $state)
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('url')
                    ->label('الرابط')
                    ->copyable()
                    ->limit(50)
                    ->url(fn (SocialLink $record) => $record->url, true),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('ترتيب')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('إضافة رابط'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('لا توجد روابط تواصل')
            ->emptyStateDescription('أضف فيسبوك، إنستغرام، واتساب، وغيرها');
    }
}

<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlumberWorkPhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'plumberWorkPhotos';
    protected static ?string $recordTitleAttribute = 'caption';
    protected static ?string $title = 'Work Photos';

    /**
     * Show this relation tab only for plumbers.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->role === User::ROLE_PLUMBER;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\FileUpload::make('image')
                ->label(__('Image'))
                ->disk('public')
                ->directory('plumber_works')
                ->image()
                ->imageEditor()
                ->downloadable()
                ->openable()
                ->required(),

            Forms\Components\TextInput::make('caption')
                ->label(__('Caption'))
                ->maxLength(120),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->disk('public')
                    ->height(60)
                    ->square(),
                Tables\Columns\TextColumn::make('caption')->limit(40),
                Tables\Columns\TextColumn::make('created_at')->since(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}

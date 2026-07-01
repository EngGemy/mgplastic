<?php

namespace App\Filament\Resources\RetailTraderResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlumbersRelationManager extends RelationManager
{
    protected static string $relationship = 'plumbers';

    protected static ?string $title = 'السباكون';

    protected static ?string $icon = 'heroicon-o-wrench-screwdriver';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof User && $ownerRecord->isRetailTrader();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('الاسم')->required(),
            Forms\Components\TextInput::make('phone')->label('الهاتف')->tel()->required(),
            Forms\Components\TextInput::make('email')->label('البريد')->email(),
            Forms\Components\TextInput::make('password')
                ->label('كلمة المرور')
                ->password()
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn ($record) => $record === null),
            Forms\Components\Textarea::make('address')->label('العنوان')->columnSpanFull(),
            Forms\Components\Toggle::make('is_approved')->label('معتمد')->default(true),
            Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('phone')->label('الهاتف'),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة سباك')
                    ->mutateFormDataUsing(fn (array $data) => array_merge($data, [
                        'role' => User::ROLE_PLUMBER,
                        'parent_distributor_id' => $this->getOwnerRecord()->id,
                    ])),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}

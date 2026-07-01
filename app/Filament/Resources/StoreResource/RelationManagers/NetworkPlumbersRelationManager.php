<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Models\SystemLabel;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NetworkPlumbersRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $title = 'سباكون الشبكة';

    protected static ?string $icon = 'heroicon-o-wrench-screwdriver';

    public function form(Form $form): Form
    {
        $plumberLabel = SystemLabel::get('plumber', 'سباك');

        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('الاسم')
                ->required(),

            Forms\Components\TextInput::make('phone')
                ->label('الهاتف')
                ->tel()
                ->required(),

            Forms\Components\TextInput::make('email')
                ->label('البريد')
                ->email(),

            Forms\Components\TextInput::make('password')
                ->label('كلمة المرور')
                ->password()
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn ($record) => $record === null),

            Forms\Components\Select::make('parent_distributor_id')
                ->label('موزع القطاعي المسؤول')
                ->options(fn () => $this->getOwnerRecord()
                    ->retailTraders()
                    ->pluck('name', 'id'))
                ->required()
                ->searchable(),

            Forms\Components\Toggle::make('is_approved')->label('معتمد')->default(true),
            Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        $plumberLabel = SystemLabel::get('plumber', 'سباك');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('role', User::ROLE_PLUMBER)
                ->whereIn('parent_distributor_id', $this->getOwnerRecord()->retailTraders()->pluck('id')))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('الهاتف'),

                Tables\Columns\TextColumn::make('parentDistributor.name')
                    ->label('موزع قطاعي')
                    ->badge()
                    ->color('info'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة '.$plumberLabel)
                    ->visible(fn () => $this->getOwnerRecord()->retailTraders()->exists())
                    ->mutateFormDataUsing(fn (array $data) => array_merge($data, [
                        'role' => User::ROLE_PLUMBER,
                    ])),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('لا يوجد '.$plumberLabel.' في هذه الشبكة')
            ->emptyStateDescription('أضف موزعاً قطاعياً أولاً ثم أضف '.$plumberLabel);
    }
}

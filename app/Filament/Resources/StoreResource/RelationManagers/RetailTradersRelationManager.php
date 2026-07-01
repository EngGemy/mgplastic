<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Filament\Concerns\HasStoreLocationForm;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RetailTradersRelationManager extends RelationManager
{
    use HasStoreLocationForm;

    protected static string $relationship = 'retailTraders';

    protected static ?string $title = 'موزعون قطاعيون';

    protected static ?string $icon = 'heroicon-o-building-storefront';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('البيانات')
                ->schema([
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('اسم الموزع')
                            ->required()
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('phone')
                            ->label('الهاتف')
                            ->tel()
                            ->required(),
                        Forms\Components\TextInput::make('email')
                            ->label('البريد')
                            ->email()
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('password')
                            ->label('كلمة المرور')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn ($record) => $record === null)
                            ->minLength(6),
                    ]),
                ]),

            Forms\Components\Section::make('الموقع')
                ->schema(self::storeLocationFields(hideCoordinates: true))
                ->columns(2)
                ->collapsed(fn ($record) => $record !== null),

            Forms\Components\Section::make('الحالة')
                ->schema(self::storeStatusFields(collapsed: true)),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('الهاتف'),

                Tables\Columns\TextColumn::make('plumbers_count')
                    ->label('سباكين')
                    ->counts('plumbers')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\IconColumn::make('has_map')
                    ->label('خريطة')
                    ->boolean()
                    ->getStateUsing(fn (User $r) => $r->hasMapLocation()),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('إضافة موزع قطاعي')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['role'] = 'retail_trader';
                        $data['parent_distributor_id'] = $this->getOwnerRecord()->id;
                        $data['is_independent'] = false;
                        $data['is_approved'] = true;
                        $data['is_active'] = true;
                        $data['is_phone_verified'] = true;
                        $data['country_id'] = $data['country_id'] ?? self::defaultLibyaCountryId();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('plumbers')
                    ->label('السباكين')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->url(fn (User $record) => \App\Filament\Resources\RetailTraderResource::getUrl('view', ['record' => $record])),

                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->emptyStateHeading('لا يوجد موزعون قطاعيون')
            ->emptyStateDescription('أضف موزعاً قطاعياً تابعاً لهذا المتجر')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }
}

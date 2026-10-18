<?php

namespace App\Filament\Resources\RetailTraderResource\RelationManagers;

use App\Filament\Trader\Resources\TraderPlumberResource;
use App\Models\User;
use App\Services\PlumberRetailLinkService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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
            Forms\Components\TextInput::make('phone')
                ->label('الهاتف')
                ->tel()
                ->required()
                ->rule(fn (?User $record) => TraderPlumberResource::phoneIsFree($record?->id)),
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
                    ->label('إضافة سباك جديد')
                    ->mutateFormDataUsing(fn (array $data) => array_merge($data, [
                        'role' => User::ROLE_PLUMBER,
                        'parent_distributor_id' => $this->getOwnerRecord()->id,
                        'is_independent' => false,
                    ]))
                    ->after(function (User $record): void {
                        app(PlumberRetailLinkService::class)->attach(
                            $this->getOwnerRecord(),
                            $record,
                            auth()->user(),
                        );
                    }),
                Tables\Actions\Action::make('linkExisting')
                    ->label('ربط سباك مسجّل')
                    ->icon('heroicon-o-link')
                    ->form([
                        Forms\Components\TextInput::make('code')
                            ->label('الرقم الموحّد أو الهاتف')
                            ->placeholder('MG-P-000012')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $result = app(PlumberRetailLinkService::class)->linkByCode(
                                $this->getOwnerRecord(),
                                (string) $data['code'],
                            );

                            Notification::make()
                                ->success()
                                ->title($result['created_link'] ? 'تمت الإضافة' : 'موجود في الشبكة')
                                ->body($result['message'])
                                ->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title('تعذّر الربط')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->actions([
                \App\Filament\Support\CustomerWhatsAppActions::welcomeTableAction(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}

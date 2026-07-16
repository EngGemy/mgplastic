<?php

namespace App\Filament\Resources\RetailTraderResource\RelationManagers;

use App\Models\User;
use App\Services\RetailNetworkLinkService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LinkedWholesalersRelationManager extends RelationManager
{
    protected static string $relationship = 'linkedWholesalers';

    protected static ?string $title = 'موزّعو الجملة المرتبطون';

    protected static ?string $modelLabel = 'موزّع جملة';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof User && $ownerRecord->isRetailTrader();
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('network_code')
                    ->label('الرقم الموحّد')
                    ->copyable()
                    ->weight('bold')
                    ->color('primary'),
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('phone')->label('الهاتف'),
                Tables\Columns\TextColumn::make('pivot.linked_at')
                    ->label('تاريخ الربط')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_primary')
                    ->label('أساسي')
                    ->getStateUsing(fn (User $record) => (int) $this->getOwnerRecord()->parent_distributor_id === (int) $record->id)
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('attach_wholesaler')
                    ->label('ربط موزّع جملة')
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->visible(fn () => in_array(auth()->user()?->role, ['super_admin', 'admin'], true))
                    ->form([
                        Forms\Components\Select::make('wholesaler_id')
                            ->label('موزّع الجملة')
                            ->options(function () {
                                $owner = $this->getOwnerRecord();
                                $linkedIds = $owner->linkedWholesalers()->pluck('users.id')
                                    ->when($owner->parent_distributor_id, fn ($c) => $c->push($owner->parent_distributor_id))
                                    ->unique()
                                    ->all();

                                return User::query()
                                    ->where('role', 'wholesale_distributor')
                                    ->where('is_approved', true)
                                    ->where('is_active', true)
                                    ->when($linkedIds !== [], fn ($q) => $q->whereNotIn('id', $linkedIds))
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (User $u) => [
                                        $u->id => trim(($u->network_code ? "[{$u->network_code}] " : '').$u->name),
                                    ]);
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('يمكن ربط نفس التاجر القطاعي بعدة موزّعي جملة في نفس الوقت.'),
                    ])
                    ->action(function (array $data) {
                        $retail = $this->getOwnerRecord();
                        $wholesaler = User::query()->findOrFail($data['wholesaler_id']);

                        app(RetailNetworkLinkService::class)->attach($wholesaler, $retail, auth()->user());

                        Notification::make()
                            ->success()
                            ->title('تم الربط')
                            ->body("ربط «{$retail->name}» بموزّع الجملة «{$wholesaler->name}».")
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('detach')
                    ->label('فك الربط')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn () => in_array(auth()->user()?->role, ['super_admin', 'admin'], true))
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        app(RetailNetworkLinkService::class)->detach($record, $this->getOwnerRecord());
                        Notification::make()->success()->title('تم فك الربط')->send();
                    }),
            ])
            ->emptyStateHeading('لا يوجد موزّع جملة مرتبط')
            ->emptyStateDescription('التاجر يمكنه العمل منفرداً، أو اربطه بموزّع جملة أو أكثر من هنا.')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }
}

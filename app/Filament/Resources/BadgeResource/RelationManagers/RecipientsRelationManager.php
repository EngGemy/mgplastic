<?php

namespace App\Filament\Resources\BadgeResource\RelationManagers;

use App\Models\Badge;
use App\Models\User;
use App\Services\Reputation\BadgeService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'السباكون الحاصلون على الشارة';

    protected static ?string $icon = 'heroicon-o-users';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('السباك')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $r) => $r->phone ?: '—'),
                Tables\Columns\TextColumn::make('pivot.awarded_at')
                    ->label('تاريخ المنح')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.source_type')
                    ->label('المصدر')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'admin_manual' => 'يدوي من الإدارة',
                        'generic' => 'تلقائي',
                        default => $state ? str_replace('_', ' ', $state) : '—',
                    })
                    ->color(fn (?string $state) => $state === 'admin_manual' ? 'warning' : 'gray'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('grant')
                    ->label('منح لسباك')
                    ->icon('heroicon-o-gift')
                    ->color('success')
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label('السباك')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->options(fn () => User::query()
                                ->where('role', User::ROLE_PLUMBER)
                                ->orderBy('name')
                                ->limit(200)
                                ->get()
                                ->mapWithKeys(fn (User $u) => [
                                    $u->id => trim($u->name.' · '.($u->phone ?: '#'.$u->id)),
                                ])
                                ->all())
                            ->getSearchResultsUsing(function (string $search) {
                                return User::query()
                                    ->where('role', User::ROLE_PLUMBER)
                                    ->where(function (Builder $q) use ($search) {
                                        $q->where('name', 'like', "%{$search}%")
                                            ->orWhere('phone', 'like', "%{$search}%");
                                    })
                                    ->orderBy('name')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (User $u) => [
                                        $u->id => trim($u->name.' · '.($u->phone ?: '#'.$u->id)),
                                    ])
                                    ->all();
                            })
                            ->getOptionLabelUsing(fn ($value) => optional(User::find($value))->name),
                        Forms\Components\Toggle::make('notify')
                            ->label('إرسال إشعار واتساب للسباك')
                            ->default(true),
                    ])
                    ->action(function (array $data, RelationManager $livewire) {
                        /** @var Badge $badge */
                        $badge = $livewire->getOwnerRecord();
                        $plumber = User::query()->findOrFail((int) $data['user_id']);
                        $granted = app(BadgeService::class)->grant(
                            $plumber,
                            $badge,
                            notify: (bool) ($data['notify'] ?? true),
                        );

                        if (! $granted) {
                            Notification::make()
                                ->warning()
                                ->title('لم يُمنح')
                                ->body('السباك ليس سباكاً أو يملك الشارة مسبقاً.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('تم منح الشارة')
                            ->body($badge->name_ar.' ← '.$plumber->name)
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('سحب')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('سحب الشارة من السباك؟')
                    ->action(function (User $record, RelationManager $livewire) {
                        /** @var Badge $badge */
                        $badge = $livewire->getOwnerRecord();
                        app(BadgeService::class)->revoke($record, $badge);
                        Notification::make()->success()->title('تم سحب الشارة')->send();
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('لم تُمنح لأحد بعد')
            ->emptyStateDescription('استخدم «منح لسباك» لمنح هذه الشارة يدوياً، أو فعّل معياراً تلقائياً ليكتسبها السباكون.')
            ->emptyStateIcon('heroicon-o-trophy');
    }
}

<?php

namespace App\Filament\Pages;

use App\Jobs\Reputation\RecomputeScoreJob;
use App\Models\PlumberScore;
use App\Models\PlumberScoreEvent;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class ReputationOverview extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.reputation-overview';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'السمعة والشارات';
    }

    public function getTitle(): string
    {
        return 'السمعة والشارات';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin', 'monitor'], true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PlumberScore::query()->with(['user'])->latest('updated_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('السباك')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('score')->label('الدرجة')->sortable()->weight('bold')->size('lg'),
                Tables\Columns\BadgeColumn::make('level')->label('المستوى'),
                Tables\Columns\TextColumn::make('confirmed_distributions_count')->label('توزيعات'),
                Tables\Columns\TextColumn::make('updated_at')->label('آخر تحديث')->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')->options([
                    'bronze' => 'برونزي', 'silver' => 'فضي', 'gold' => 'ذهبي', 'diamond' => 'ماسي',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('recompute')
                    ->label('إعادة حساب')
                    ->visible(fn () => in_array(auth()->user()?->role, ['super_admin', 'admin'], true))
                    ->action(function (PlumberScore $record) {
                        RecomputeScoreJob::runSync((int) $record->user_id, 'admin_recompute');
                        Notification::make()->success()->title('تم إعادة الحساب')->send();
                    }),
                Tables\Actions\Action::make('events')
                    ->label('السجل')
                    ->modalHeading('سجل الدرجة')
                    ->modalContent(fn (PlumberScore $record) => view('filament.pages.partials.score-events', [
                        'events' => PlumberScoreEvent::query()->where('user_id', $record->user_id)->latest('created_at')->limit(30)->get(),
                        'components' => $record->components,
                    ])),
            ]);
    }
}

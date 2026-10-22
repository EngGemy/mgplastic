<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BadgeResource;
use App\Jobs\Reputation\RecomputeScoreJob;
use App\Models\Badge;
use App\Models\PlumberScore;
use App\Models\PlumberScoreEvent;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\Reputation\BadgeService;
use Filament\Forms;
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

    public string $badgesUrl = '';

    public string $settingsUrl = '';

    public string $createBadgeUrl = '';

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
        return 'لوحة السمعة والشارات';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin', 'monitor'], true);
    }

    public function mount(): void
    {
        $this->badgesUrl = BadgeResource::getUrl('index');
        $this->createBadgeUrl = BadgeResource::getUrl('create');
        $this->settingsUrl = ReputationWarrantySettings::getUrl();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PlumberScore::query()->with(['user'])->latest('updated_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('السباك')
                    ->searchable()
                    ->sortable()
                    ->description(fn (PlumberScore $r) => $r->user?->phone ?: '—'),
                Tables\Columns\TextColumn::make('score')
                    ->label('درجة السمعة')
                    ->sortable()
                    ->weight('bold')
                    ->suffix(' / 100'),
                Tables\Columns\TextColumn::make('level')
                    ->label('المستوى')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $this->levelLabel($state))
                    ->color(fn (?string $state) => match ($state) {
                        'diamond' => 'info',
                        'gold' => 'warning',
                        'silver' => 'gray',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('confirmed_distributions_count')
                    ->label('توزيعات مؤكدة')
                    ->sortable(),
                Tables\Columns\TextColumn::make('badges_count')
                    ->label('شارات')
                    ->getStateUsing(fn (PlumberScore $r) => UserBadge::query()->where('user_id', $r->user_id)->count())
                    ->badge()
                    ->color('success'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')->label('المستوى')->options([
                    'bronze' => 'برونزي',
                    'silver' => 'فضي',
                    'gold' => 'ذهبي',
                    'diamond' => 'ماسي',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('grant_badge')
                    ->label('منح شارة')
                    ->icon('heroicon-o-gift')
                    ->color('success')
                    ->visible(fn () => in_array(auth()->user()?->role, ['super_admin', 'admin'], true))
                    ->form([
                        Forms\Components\Select::make('badge_id')
                            ->label('الشارة')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->options(fn () => Badge::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn (Badge $b) => [
                                    $b->id => $b->name_ar.' · '.match ($b->tier) {
                                        'legendary' => 'أسطورية',
                                        'epic' => 'ملحمية',
                                        'rare' => 'نادرة',
                                        default => 'شائعة',
                                    },
                                ])
                                ->all()),
                        Forms\Components\Toggle::make('notify')
                            ->label('إشعار واتساب للسباك')
                            ->default(true),
                    ])
                    ->action(function (PlumberScore $record, array $data) {
                        $plumber = User::query()->findOrFail((int) $record->user_id);
                        $badge = Badge::query()->findOrFail((int) $data['badge_id']);
                        $granted = app(BadgeService::class)->grant(
                            $plumber,
                            $badge,
                            notify: (bool) ($data['notify'] ?? true),
                        );

                        if (! $granted) {
                            Notification::make()
                                ->warning()
                                ->title('لم تُمنح')
                                ->body('السباك يملك هذه الشارة مسبقاً.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('تم منح الشارة')
                            ->body($badge->name_ar.' ← '.$plumber->name)
                            ->send();
                    }),
                Tables\Actions\Action::make('recompute')
                    ->label('إعادة حساب')
                    ->visible(fn () => in_array(auth()->user()?->role, ['super_admin', 'admin'], true))
                    ->action(function (PlumberScore $record) {
                        RecomputeScoreJob::runSync((int) $record->user_id, 'admin_recompute');
                        Notification::make()->success()->title('تم إعادة حساب السمعة')->send();
                    }),
                Tables\Actions\Action::make('events')
                    ->label('تفاصيل الدرجة')
                    ->modalHeading(fn (PlumberScore $record) => 'سجل درجة: '.($record->user?->name ?? ''))
                    ->modalContent(fn (PlumberScore $record) => view('filament.pages.partials.score-events', [
                        'events' => PlumberScoreEvent::query()->where('user_id', $record->user_id)->latest('created_at')->limit(30)->get(),
                        'components' => $record->components,
                        'badges' => UserBadge::query()
                            ->with('badge')
                            ->where('user_id', $record->user_id)
                            ->latest('awarded_at')
                            ->get(),
                    ])),
            ])
            ->emptyStateHeading('لا درجات سمعة بعد')
            ->emptyStateDescription('تظهر درجات السباكين هنا بعد نشاط التوزيعات وإعادة الحساب.')
            ->emptyStateIcon('heroicon-o-chart-bar');
    }

    protected function levelLabel(?string $state): string
    {
        return match ($state) {
            'bronze' => 'برونزي',
            'silver' => 'فضي',
            'gold' => 'ذهبي',
            'diamond' => 'ماسي',
            default => $state ?: '—',
        };
    }
}

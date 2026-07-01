<?php

namespace App\Filament\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Models\Country;
use App\Models\City;
use App\Support\AdminPermissions;
use App\Support\UserRoles;
use Filament\Actions;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsersReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.pages.users-report';

    public string $roleTab = 'all';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(AdminPermissions::USERS_VIEW)
            || $user?->canAdminPermission(AdminPermissions::REPORTS_VIEW);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'المستخدمون';
    }

    public static function getNavigationLabel(): string
    {
        return 'تقرير المستخدمين';
    }

    public function getTitle(): string
    {
        return 'تقرير المستخدمين والأدوار';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createUser')
                ->label('إضافة مستخدم')
                ->icon('heroicon-o-user-plus')
                ->color('primary')
                ->url(fn () => UserResource::getUrl('create'))
                ->visible(fn () => auth()->user()?->canAdminPermission(AdminPermissions::USERS_MANAGE)),
        ];
    }

    /** @return array<string, int> */
    public function getRoleCounts(): array
    {
        $counts = User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role')
            ->all();

        $tabs = [];
        foreach (UserRoles::reportTabs() as $key => $tab) {
            if ($key === 'all') {
                $tabs[$key] = array_sum($counts);

                continue;
            }

            $tabs[$key] = collect($tab['roles'])->sum(fn ($role) => (int) ($counts[$role] ?? 0));
        }

        return $tabs;
    }

    /** @return array<int, array{label: string, value: int, color: string}> */
    public function getSummaryStats(): array
    {
        return [
            ['label' => 'موزعو الجملة', 'value' => User::where('role', 'wholesale_distributor')->count(), 'color' => 'blue'],
            ['label' => 'تجار القطاعي', 'value' => User::where('role', 'retail_trader')->count(), 'color' => 'amber'],
            ['label' => 'السبّاكون', 'value' => User::where('role', User::ROLE_PLUMBER)->count(), 'color' => 'green'],
            ['label' => 'مديرو النظام', 'value' => User::whereIn('role', ['super_admin', 'admin'])->count(), 'color' => 'purple'],
            ['label' => 'نشطون', 'value' => User::where('is_active', true)->where('is_approved', true)->count(), 'color' => 'teal'],
        ];
    }

    public function setRoleTab(string $tab): void
    {
        $this->roleTab = $tab;
        $this->resetTable();
    }

    protected function baseQuery(): Builder
    {
        return User::query()
            ->with([
                'country:id,name_en,name_ar',
                'city:id,country_id,name_en,name_ar',
                'parentDistributor:id,name,role,brand_name',
                'walletAccounts',
            ]);
    }

    protected function applyRoleTab(Builder $query): Builder
    {
        $tab = UserRoles::reportTabs()[$this->roleTab] ?? null;

        if (! $tab || $this->roleTab === 'all' || $tab['roles'] === []) {
            return $query;
        }

        return $query->whereIn('role', $tab['roles']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->applyRoleTab($this->baseQuery()))
            ->columns([
                Tables\Columns\ImageColumn::make('profile_photo')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(40),

                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $record) => $record->brand_name ?: null),

                Tables\Columns\TextColumn::make('role')
                    ->label('الدور')
                    ->badge()
                    ->color(fn ($state) => UserRoles::color($state))
                    ->formatStateUsing(fn ($state) => UserRoles::label($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('parentDistributor.name')
                    ->label('تابع لـ')
                    ->placeholder('—')
                    ->description(fn (User $record) => $record->parentDistributor
                        ? UserRoles::label($record->parentDistributor->role)
                        : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('الهاتف')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('wallet_points')
                    ->label('النقاط')
                    ->state(fn (User $record) => (int) $record->walletAccounts->sum('balance_points'))
                    ->numeric()
                    ->badge()
                    ->color('warning')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('city.name_ar')
                    ->label('المدينة')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_phone_verified')
                    ->label('موثّق')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_approved')
                    ->label('معتمد')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ التسجيل')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('الدور')
                    ->options(UserRoles::selectOptions())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('country_id')
                    ->label('الدولة')
                    ->options(fn () => Country::query()->orderBy('name_ar')->pluck('name_ar', 'id'))
                    ->searchable(),

                Tables\Filters\SelectFilter::make('city_id')
                    ->label('المدينة')
                    ->options(fn () => City::query()->orderBy('name_ar')->pluck('name_ar', 'id'))
                    ->searchable(),

                Tables\Filters\TernaryFilter::make('is_phone_verified')->label('موثّق'),
                Tables\Filters\TernaryFilter::make('is_approved')->label('معتمد'),
                Tables\Filters\TernaryFilter::make('is_active')->label('نشط'),

                Tables\Filters\Filter::make('created_between')
                    ->label('فترة التسجيل')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('من')->native(false),
                        Forms\Components\DatePicker::make('to')->label('إلى')->native(false),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label('تعديل')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (User $record) => UserResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn () => auth()->user()?->canAdminPermission(AdminPermissions::USERS_MANAGE)),

                Tables\Actions\Action::make('approve')
                    ->label('اعتماد')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (User $record) => ! $record->is_approved && auth()->user()?->canAdminPermission(AdminPermissions::USERS_MANAGE))
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->forceFill(['is_approved' => true, 'approved_at' => now()])->save()),

                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $record) => $record->is_active ? 'تعطيل' : 'تفعيل')
                    ->icon(fn (User $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-bolt')
                    ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                    ->visible(fn () => auth()->user()?->canAdminPermission(AdminPermissions::USERS_MANAGE))
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->forceFill([
                            'is_active' => ! $record->is_active,
                            'deactivated_at' => $record->is_active ? now() : null,
                        ])->save();
                    }),

                \App\Filament\Support\UserNotificationActions::tableAction(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportCsv')
                    ->label('تصدير CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => $this->exportCsv()),
            ])
            ->emptyStateHeading('لا يوجد مستخدمون')
            ->emptyStateDescription('غيّر التبويب أو أضف مستخدماً جديداً')
            ->emptyStateIcon('heroicon-o-users')
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50, 100]);
    }

    protected function exportCsv(): StreamedResponse
    {
        $filename = 'users_report_'.now()->format('Ymd_His').'.csv';
        $query = clone $this->getFilteredTableQuery();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'الاسم', 'الدور', 'الهاتف', 'البريد', 'تابع لـ', 'معتمد', 'نشط', 'تاريخ التسجيل']);

            $query->orderBy('id')->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $u) {
                    fputcsv($out, [
                        $u->id,
                        $u->name,
                        UserRoles::label($u->role),
                        $u->phone,
                        $u->email,
                        $u->parentDistributor?->name,
                        $u->is_approved ? '1' : '0',
                        $u->is_active ? '1' : '0',
                        $u->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

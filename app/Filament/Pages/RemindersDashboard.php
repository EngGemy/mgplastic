<?php

namespace App\Filament\Pages;

use App\Models\ReminderLog;
use App\Services\Ai\GeminiMessageService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class RemindersDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static string $view = 'filament.pages.reminders-dashboard';

    protected static ?int $navigationSort = 45;

    public ?array $data = [];

    public ?string $previewText = null;

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'التذكيرات';
    }

    public function getTitle(): string
    {
        return 'التذكيرات والذكاء الاصطناعي';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin', 'monitor'], true);
    }

    public function mount(): void
    {
        $this->form->fill([
            'enabled' => config('reminders.enabled'),
            'admin_only' => config('reminders.admin_only'),
            'per_hour' => config('reminders.per_hour'),
            'daily_limit' => config('reminders.daily_limit'),
            'timezone' => config('reminders.timezone'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Toggle::make('enabled')->label('REMINDERS_ENABLED')->disabled(),
            Forms\Components\Toggle::make('admin_only')->label('REMINDERS_ADMIN_ONLY')->disabled(),
            Forms\Components\TextInput::make('per_hour')->label('حد الساعة')->disabled(),
            Forms\Components\TextInput::make('daily_limit')->label('الحد اليومي')->disabled(),
            Forms\Components\TextInput::make('timezone')->label('المنطقة')->disabled(),
        ])->statePath('data');
    }

    public function previewGemini(GeminiMessageService $ai): void
    {
        $this->previewText = $ai->compose('daily_plumber', [
            'balance' => '1,200',
            'earned' => 40,
            'streak_hint' => 'سلسلتك *3* أسابيع',
            'points' => 1200,
        ]);
        Notification::make()->success()->title('معاينة جاهزة')->send();
    }

    public function getStatsProperty(): array
    {
        $today = ReminderLog::query()->whereDate('created_at', today());

        return [
            'sent' => (clone $today)->where('status', 'sent')->count(),
            'skipped' => (clone $today)->where('status', 'skipped')->count(),
            'failed' => (clone $today)->where('status', 'failed')->count(),
            'ai' => (clone $today)->where('ai_used', true)->count(),
        ];
    }

    public function getLogsProperty()
    {
        return ReminderLog::query()->latest('created_at')->limit(40)->get();
    }
}

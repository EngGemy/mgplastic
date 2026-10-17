<?php

namespace App\Filament\Pages;

use App\Services\Ai\GeminiClient;
use App\Services\DecisionReportsService;
use App\Support\AdminPermissions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DecisionReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.decision-reports';

    public string $activeTab = 'overview';

    public int $windowDays = 30;

    public string $geminiQuestion = '';

    public ?string $geminiAnswer = null;

    public ?string $geminiError = null;

    public static function getNavigationGroup(): ?string
    {
        return 'التقارير';
    }

    public static function getNavigationLabel(): string
    {
        return 'مركز القرارات';
    }

    public function getTitle(): string
    {
        return 'مركز التقارير وصناعة القرار';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(AdminPermissions::REPORTS_VIEW);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function updatedWindowDays(): void
    {
        $this->windowDays = max(7, min(90, (int) $this->windowDays));
        $this->geminiAnswer = null;
        $this->geminiError = null;
    }

    /** @return array<string, mixed> */
    public function getSnapshot(): array
    {
        return app(DecisionReportsService::class)->snapshot($this->windowDays);
    }

    public function geminiEnabled(): bool
    {
        return app(GeminiClient::class)->enabled();
    }

    public function askGemini(): void
    {
        $this->geminiAnswer = null;
        $this->geminiError = null;

        $question = trim($this->geminiQuestion);
        if ($question === '') {
            Notification::make()->warning()->title('اكتب سؤالاً أولاً')->send();

            return;
        }

        $context = app(DecisionReportsService::class)->geminiContext($this->windowDays);
        $result = app(GeminiClient::class)->ask($question, $context);

        if (! ($result['ok'] ?? false)) {
            $this->geminiError = $result['error'] ?? 'تعذّر الحصول على إجابة';
            Notification::make()->danger()->title('Gemini')->body($this->geminiError)->send();

            return;
        }

        $this->geminiAnswer = $result['answer'];
        Notification::make()->success()->title('تم تحليل السؤال')->send();
    }

    /** Suggested prompts for decision makers. */
    public function askSuggestion(string $q): void
    {
        $this->geminiQuestion = $q;
        $this->activeTab = 'gemini';
        $this->askGemini();
    }

    /** @return list<array{key:string,label:string,icon:string}> */
    public function tabs(): array
    {
        return [
            ['key' => 'overview', 'label' => 'نظرة تنفيذية', 'icon' => '📊'],
            ['key' => 'network', 'label' => 'قمع الشبكة', 'icon' => '🕸️'],
            ['key' => 'orders', 'label' => 'الطلبيات', 'icon' => '📦'],
            ['key' => 'points', 'label' => 'تدفق النقاط', 'icon' => '⭐'],
            ['key' => 'finance', 'label' => 'المالية', 'icon' => '💰'],
            ['key' => 'inventory', 'label' => 'المنتجات', 'icon' => '🛒'],
            ['key' => 'gemini', 'label' => 'اسأل Gemini', 'icon' => '✨'],
        ];
    }
}

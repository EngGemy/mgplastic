<?php

namespace App\Filament\Pages;

use App\Services\Ai\GeminiClient;
use App\Services\DecisionReportsService;
use App\Support\AdminPermissions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DecisionReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.decision-reports';

    protected static ?string $slug = 'decision-hub';

    public string $activeTab = 'overview';

    public int $windowDays = 30;

    public string $geminiQuestion = '';

    public ?string $geminiAnswer = null;

    public ?string $geminiError = null;

    public static function getNavigationGroup(): ?string
    {
        return 'صناعة القرار';
    }

    public static function getNavigationLabel(): string
    {
        return 'غرفة القرارات';
    }

    public function getTitle(): string
    {
        return 'غرفة صناعة القرار';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->isAdminUser()
            || $user->canAdminPermission(AdminPermissions::REPORTS_VIEW);
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
        $this->activeTab = 'gemini';

        $question = trim($this->geminiQuestion);
        if ($question === '') {
            Notification::make()->warning()->title('اكتب سؤالاً أولاً')->send();

            return;
        }

        $context = app(DecisionReportsService::class)->geminiContext($this->windowDays);
        $result = app(GeminiClient::class)->ask($question, $context);

        if (! ($result['ok'] ?? false)) {
            $this->geminiError = $result['error'] ?? 'تعذّر الحصول على إجابة';
            Notification::make()->danger()->title('مساعد القرار')->body($this->geminiError)->send();

            return;
        }

        $this->geminiAnswer = $result['answer'];
        Notification::make()->success()->title('تم تجهيز التوصية')->send();
    }

    public function askSuggestion(string $q): void
    {
        $this->geminiQuestion = $q;
        $this->askGemini();
    }

    /** @return list<array{key:string,label:string,hint:string}> */
    public function tabs(): array
    {
        return [
            ['key' => 'overview', 'label' => 'الملخّص التنفيذي', 'hint' => 'KPI + تنبيهات'],
            ['key' => 'network', 'label' => 'قمع الشبكة', 'hint' => 'مصنع → سباك'],
            ['key' => 'orders', 'label' => 'الطلبيات', 'hint' => 'حالة وقنوات'],
            ['key' => 'points', 'label' => 'تدفق النقاط', 'hint' => 'منح ومرتجع'],
            ['key' => 'finance', 'label' => 'المالية', 'hint' => 'سحوبات ومحافظ'],
            ['key' => 'inventory', 'label' => 'المنتجات', 'hint' => 'كتالوج ونقاط'],
            ['key' => 'gemini', 'label' => 'برومبت القرار', 'hint' => 'اسأل المساعد'],
        ];
    }

    /** @return list<array{q:string,label:string,tag:string}> */
    public function decisionPrompts(): array
    {
        return [
            [
                'tag' => 'تشغيل',
                'label' => 'أهم قرار هذا الأسبوع',
                'q' => 'بناءً على التنبيهات والمؤشرات الحالية، ما أهم قرار تشغيلي يجب اتخاذه هذا الأسبوع؟ اذكر السبب وخطوة التنفيذ الأولى.',
            ],
            [
                'tag' => 'شبكة',
                'label' => 'عنق الزجاجة في الشبكة',
                'q' => 'أين أكبر عنق زجاجة في شبكة التوزيع (مصنع→جملة→قطاعي→سباك) حالياً؟ وما الإجراء المقترح خلال 14 يوماً؟',
            ],
            [
                'tag' => 'طلبيات',
                'label' => 'تحسين نسبة التسليم',
                'q' => 'هل نسبة تسليم الطلبيات جيدة؟ قارن المفتوح بالمسلَّم واقترح تحسينات عملية للقطاعي والجملة.',
            ],
            [
                'tag' => 'مالية',
                'label' => 'أولوية السحوبات',
                'q' => 'ما أولوية السحوبات والفواتير المعلّقة لصانع القرار؟ رتّب المخاطر واقترح ترتيب المعالجة.',
            ],
            [
                'tag' => 'نقاط',
                'label' => 'صحة تدفق النقاط',
                'q' => 'حلّل تدفق النقاط (مصنع / متبقي جملة / موزّع / أرصدة سباكين) وحدد إن كان هناك تسرب أو تكدّس يحتاج قراراً.',
            ],
            [
                'tag' => 'خطة',
                'label' => '3 قرارات للأسبوع القادم',
                'q' => 'اقترح 3 قرارات تشغيلية للأسبوع القادم مرتبة حسب الأثر، مع معيار نجاح بسيط لكل قرار.',
            ],
        ];
    }

    /** System brief shown to the decision maker (UI copy of the AI role). */
    public function decisionBrief(): string
    {
        return 'أنت في غرفة قرارات MG Plastic. البيانات أدناه مأخوذة من النظام الحي للفترة المختارة. '
            .'استخدم التبويبات للمراجعة التفصيلية، أو اكتب برومبت القرار في اللوحة اليسرى ليحصل المساعد على ملخص المؤشرات ويقترح إجراءات عملية بالعربية.';
    }
}

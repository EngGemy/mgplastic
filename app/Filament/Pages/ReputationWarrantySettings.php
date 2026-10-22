<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BadgeResource;
use App\Models\Badge;
use App\Models\PlumberScore;
use App\Models\WarrantySetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class ReputationWarrantySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static string $view = 'filament.pages.reputation-warranty-settings';

    protected static ?int $navigationSort = 41;

    public ?array $data = [];

    /** @var array<int, array<string, mixed>> */
    public array $levelCards = [];

    /** @var array<string, int> */
    public array $weights = [];

    /** @var array<int, array<string, mixed>> */
    public array $badgeCards = [];

    /** @var array<string, int> */
    public array $levelCounts = [];

    public string $badgesUrl = '';

    public string $overviewUrl = '';

    public static function getNavigationGroup(): ?string
    {
        return 'نظام النقاط';
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات السمعة والضمان';
    }

    public function getTitle(): string
    {
        return 'استوديو السمعة والضمان';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public function mount(): void
    {
        $levels = (array) config('reputation.levels', []);
        $this->weights = (array) config('reputation.weights', []);
        $this->levelCounts = PlumberScore::query()
            ->selectRaw('level, count(*) as c')
            ->groupBy('level')
            ->pluck('c', 'level')
            ->map(fn ($c) => (int) $c)
            ->all();

        $this->levelCards = $this->buildLevelCards($levels);
        $this->badgeCards = $this->buildBadgeCards();
        $this->badgesUrl = BadgeResource::getUrl('index');
        $this->overviewUrl = ReputationOverview::getUrl();

        $this->form->fill([
            'reputation_enabled' => (bool) config('reputation.enabled'),
            'warranty_enabled' => (bool) config('warranty.enabled'),
            'min_level' => WarrantySetting::get('min_level', config('warranty.min_level')),
            'min_score' => (int) WarrantySetting::get('min_score', config('warranty.min_score')),
            'min_distributions' => (int) WarrantySetting::get('min_distributions', config('warranty.min_distributions')),
            'default_months' => (int) WarrantySetting::get('default_months', config('warranty.default_months')),
            'terms_ar' => (string) WarrantySetting::get('terms_ar', config('warranty.terms_ar')),
            'daily_issue_cap' => (int) config('warranty.daily_issue_cap', 10),
            'resend_cooldown_hours' => (int) config('warranty.resend_cooldown_hours', 24),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->extraAttributes(['class' => 'mg-rep-form-shell'])
                    ->schema([
                        Forms\Components\Grid::make(12)->schema([
                            Forms\Components\Select::make('min_level')
                                ->label('أدنى مستوى سمعة')
                                ->native(false)
                                ->live()
                                ->options([
                                    'bronze' => 'برونزي',
                                    'silver' => 'فضي',
                                    'gold' => 'ذهبي',
                                    'diamond' => 'ماسي',
                                ])
                                ->helperText('المستوى المطلوب قبل فتح إصدار الضمان.')
                                ->columnSpan(['default' => 12, 'md' => 6, 'lg' => 3]),

                            Forms\Components\TextInput::make('min_score')
                                ->label('أدنى درجة')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->live(debounce: 300)
                                ->suffix('/ 100')
                                ->helperText('درجة السمعة المحسوبة.')
                                ->columnSpan(['default' => 12, 'md' => 6, 'lg' => 3]),

                            Forms\Components\TextInput::make('min_distributions')
                                ->label('أدنى توزيعات مؤكدة')
                                ->numeric()
                                ->minValue(0)
                                ->live(debounce: 300)
                                ->suffix('توزيع')
                                ->helperText('إثبات حجم عمل حقيقي.')
                                ->columnSpan(['default' => 12, 'md' => 6, 'lg' => 3]),

                            Forms\Components\TextInput::make('default_months')
                                ->label('مدة الضمان')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(60)
                                ->live(debounce: 300)
                                ->suffix('شهر')
                                ->helperText('تُطبَّق على الضمانات الجديدة.')
                                ->columnSpan(['default' => 12, 'md' => 6, 'lg' => 3]),
                        ]),

                        Forms\Components\Textarea::make('terms_ar')
                            ->label('نص شروط الضمان (يظهر للعميل)')
                            ->rows(5)
                            ->required()
                            ->extraAttributes(['dir' => 'rtl', 'class' => 'mg-rep-terms'])
                            ->helperText('يُطبع على شهادة الضمان ورسائل واتساب.'),

                        Forms\Components\Hidden::make('reputation_enabled'),
                        Forms\Components\Hidden::make('warranty_enabled'),
                        Forms\Components\Hidden::make('daily_issue_cap'),
                        Forms\Components\Hidden::make('resend_cooldown_hours'),

                        Forms\Components\Placeholder::make('gate_preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => view('filament.pages.partials.reputation-gate-preview', [
                                'minLevel' => (string) $get('min_level'),
                                'minScore' => (int) $get('min_score'),
                                'minDistributions' => (int) $get('min_distributions'),
                                'months' => (int) $get('default_months'),
                                'levels' => (array) config('reputation.levels', []),
                            ])),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $d = $this->form->getState();

        foreach (['min_level', 'min_score', 'min_distributions', 'default_months', 'terms_ar'] as $key) {
            WarrantySetting::put($key, $d[$key] ?? null);
        }

        Notification::make()
            ->success()
            ->title('تم حفظ سياسة الضمان')
            ->body('عتبات الإصدار والشروط محدّثة فورًا للسباكين المؤهّلين.')
            ->send();
    }

    /**
     * @param  array<string, array<string, mixed>>  $levels
     * @return array<int, array<string, mixed>>
     */
    protected function buildLevelCards(array $levels): array
    {
        $plans = [
            'bronze' => [
                'intent' => 'بوابة الدخول',
                'plan' => 'إثبات أن السباك نشط في الشبكة — طلبات أولية وبداية حساب السمعة.',
                'unlocks' => ['حساب درجة', 'شارات شائعة', 'متابعة السلسلة'],
                'metal' => 'bronze',
            ],
            'silver' => [
                'intent' => 'استقرار الأداء',
                'plan' => 'انتظام أسبوعي وتقليل المرتجعات — الثقة تُبنى بالتكرار لا بالحجم وحده.',
                'unlocks' => ['شارات نادرة', 'وزن الاتساق', 'مسار الذهب'],
                'metal' => 'silver',
            ],
            'gold' => [
                'intent' => 'عتبة الضمان',
                'plan' => 'مستوى الثقة الذي يفتح إصدار ضمان المصنع لعميل السباك — قرار تشغيلي لا مجرّد وسام.',
                'unlocks' => ['إصدار الضمان', 'شارة محترف موثوق', 'أولوية السمعة'],
                'metal' => 'gold',
            ],
            'diamond' => [
                'intent' => 'نخبة الشبكة',
                'plan' => 'أعلى موثوقية تشغيلية — حجم + اتساق + صفر مشاكل تقريبًا. واجهة العلامة أمام العملاء الكبار.',
                'unlocks' => ['شارة ماسي أسطورية', 'سقف السمعة', 'مرجع الجودة'],
                'metal' => 'diamond',
            ],
        ];

        $cards = [];
        foreach (['bronze', 'silver', 'gold', 'diamond'] as $key) {
            $range = $levels[$key] ?? ['min' => 0, 'max' => 0, 'label' => $key];
            $meta = $plans[$key];
            $cards[] = [
                'key' => $key,
                'label' => (string) ($range['label'] ?? $key),
                'min' => (int) ($range['min'] ?? 0),
                'max' => (int) ($range['max'] ?? 0),
                'count' => (int) ($this->levelCounts[$key] ?? 0),
                'intent' => $meta['intent'],
                'plan' => $meta['plan'],
                'unlocks' => $meta['unlocks'],
                'metal' => $meta['metal'],
            ];
        }

        return $cards;
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildBadgeCards(): array
    {
        /** @var Collection<int, Badge> $badges */
        $badges = Badge::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'key', 'name_ar', 'icon', 'tier', 'rule', 'is_active', 'description_ar']);

        return $badges->map(function (Badge $badge) {
            $type = (string) data_get($badge->rule, 'type', '');
            $min = data_get($badge->rule, 'min');
            $ruleLabel = BadgeResource::ruleTypeOptions()[$type] ?? $type;
            if ($min !== null && $min !== '') {
                $ruleLabel .= ' · '.$min;
            }

            return [
                'id' => $badge->id,
                'name' => $badge->name_ar,
                'key' => $badge->key,
                'tier' => $badge->tier,
                'glyph' => BadgeResource::iconGlyph($badge->icon),
                'rule' => $ruleLabel,
                'active' => (bool) $badge->is_active,
                'edit_url' => BadgeResource::getUrl('edit', ['record' => $badge]),
            ];
        })->all();
    }

    /** @return array<string, string> */
    public function weightLabels(): array
    {
        return [
            'volume' => 'حجم التوزيع',
            'consistency' => 'الاتساق الأسبوعي',
            'returns' => 'انخفاض المرتجعات',
            'warranty' => 'نشاط الضمان',
            'tenure' => 'أقدمية الحساب',
        ];
    }
}

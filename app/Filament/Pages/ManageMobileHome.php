<?php

namespace App\Filament\Pages;

use App\Models\HomeHighlight;
use App\Models\HomeMobileSetting;
use App\Models\HomeProductType;
use App\Services\HomeMobileLayoutService;
use App\Support\AdminPermissions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class ManageMobileHome extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?int $navigationSort = 41;

    protected static string $view = 'filament.pages.manage-mobile-home';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(AdminPermissions::USERS_MANAGE);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'المحتوى';
    }

    public static function getNavigationLabel(): string
    {
        return 'رئيسية التطبيق';
    }

    public function getTitle(): string
    {
        return 'سكاشن الرئيسية في التطبيق';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function mount(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $settings = HomeMobileSetting::instance();
        $data = $settings->only([
            'banner_title', 'banner_subtitle', 'banner_cta_text', 'banner_cta_link',
            'banner_image', 'banner_is_active',
            'main_sections_title', 'new_products_title', 'stores_title',
        ]);

        if (! empty($data['banner_image']) && is_string($data['banner_image'])) {
            $data['banner_image'] = [$data['banner_image']];
        }

        $data['product_types'] = HomeProductType::query()->ordered()->get()->map(function (HomeProductType $t) {
            return [
                'id' => $t->id,
                'key' => $t->key,
                'name' => $t->name,
                'subtitle' => $t->subtitle,
                'icon' => $t->icon,
                'image' => $t->image ? [$t->image] : [],
                'keywords' => implode("\n", $t->keywords ?? []),
                'exclude_keywords' => implode("\n", $t->exclude_keywords ?? []),
                'sort_order' => $t->sort_order,
                'is_active' => $t->is_active,
            ];
        })->values()->all();

        $data['highlights'] = HomeHighlight::query()->ordered()->get()->map(function (HomeHighlight $h) {
            return [
                'id' => $h->id,
                'key' => $h->key,
                'label' => $h->label,
                'icon' => $h->icon,
                'link' => $h->link,
                'deep_link' => $h->deep_link,
                'category_match' => implode("\n", $h->category_match ?? []),
                'category_prefer' => implode("\n", $h->category_prefer ?? []),
                'category_exclude' => implode("\n", $h->category_exclude ?? []),
                'sort_order' => $h->sort_order,
                'is_active' => $h->is_active,
            ];
        })->values()->all();

        $this->form->fill($data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('mobile_home')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('البانر الأخضر')
                            ->icon('heroicon-o-sparkles')
                            ->schema([
                                Forms\Components\Toggle::make('banner_is_active')
                                    ->label('إظهار البانر في التطبيق')
                                    ->inline(false)
                                    ->live(),
                                Forms\Components\TextInput::make('banner_title')
                                    ->label('عنوان البانر')
                                    ->required()
                                    ->maxLength(120)
                                    ->live(onBlur: true)
                                    ->placeholder('منتجات MG'),
                                Forms\Components\TextInput::make('banner_subtitle')
                                    ->label('النص الفرعي')
                                    ->maxLength(200)
                                    ->live(onBlur: true)
                                    ->placeholder('أداء أعلى ... واعتمادية تدوم'),
                                Forms\Components\TextInput::make('banner_cta_text')
                                    ->label('نص الزر')
                                    ->maxLength(80)
                                    ->live(onBlur: true)
                                    ->placeholder('اكتشف المزيد'),
                                Forms\Components\TextInput::make('banner_cta_link')
                                    ->label('رابط الزر / Deep link')
                                    ->maxLength(255)
                                    ->placeholder('mgplastic://catalog'),
                                Forms\Components\FileUpload::make('banner_image')
                                    ->label('صورة/رسم البانر (اختياري)')
                                    ->disk('public')
                                    ->directory('home-mobile')
                                    ->image()
                                    ->imageEditor()
                                    ->maxSize(4096)
                                    ->helperText('لو فاضي التطبيق يستخدم الرسم الافتراضي.'),
                            ])
                            ->columns(2),

                        Forms\Components\Tabs\Tab::make('عناوين السكاشن')
                            ->icon('heroicon-o-bars-3-bottom-right')
                            ->schema([
                                Forms\Components\TextInput::make('main_sections_title')
                                    ->label('عنوان «الأقسام الرئيسية»')
                                    ->required()
                                    ->maxLength(120)
                                    ->live(onBlur: true),
                                Forms\Components\TextInput::make('new_products_title')
                                    ->label('عنوان «جديد منتجات MG»')
                                    ->required()
                                    ->maxLength(120)
                                    ->live(onBlur: true),
                                Forms\Components\TextInput::make('stores_title')
                                    ->label('عنوان «المتاجر»')
                                    ->required()
                                    ->maxLength(120)
                                    ->live(onBlur: true),
                            ]),

                        Forms\Components\Tabs\Tab::make('الأقسام الرئيسية')
                            ->icon('heroicon-o-squares-2x2')
                            ->schema([
                                Forms\Components\Repeater::make('product_types')
                                    ->label('بطاقات الأقسام')
                                    ->helperText('كل بطاقة = نوع قطعة في الرئيسية (أكواع · تيهات · …). الترتيب من الأعلى للأسفل.')
                                    ->collapsible()
                                    ->cloneable()
                                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? $state['key'] ?? 'قسم جديد')
                                    ->schema([
                                        Forms\Components\Hidden::make('id'),
                                        Forms\Components\TextInput::make('key')
                                            ->label('المفتاح (API)')
                                            ->required()
                                            ->maxLength(64)
                                            ->helperText('مثال: elbow · tee — لا تغيّره إلا لو التطبيق جاهز للمفتاح الجديد.'),
                                        Forms\Components\TextInput::make('name')
                                            ->label('الاسم الظاهر')
                                            ->required()
                                            ->maxLength(120)
                                            ->live(onBlur: true),
                                        Forms\Components\TextInput::make('subtitle')
                                            ->label('النص الفرعي')
                                            ->maxLength(200)
                                            ->live(onBlur: true),
                                        Forms\Components\Select::make('icon')
                                            ->label('أيقونة')
                                            ->options([
                                                'pipe' => 'ماسورة',
                                                'tee' => 'تيه',
                                                'valve' => 'محبس',
                                                'elbow' => 'كوع',
                                                'cap' => 'سداد',
                                            ])
                                            ->searchable()
                                            ->native(false),
                                        Forms\Components\FileUpload::make('image')
                                            ->label('صورة البطاقة')
                                            ->disk('public')
                                            ->directory('product-types')
                                            ->image()
                                            ->maxSize(3072),
                                        Forms\Components\Textarea::make('keywords')
                                            ->label('كلمات المطابقة (سطر لكل كلمة)')
                                            ->rows(3)
                                            ->helperText('تُستخدم لربط المنتجات تلقائياً بهذا القسم.'),
                                        Forms\Components\Textarea::make('exclude_keywords')
                                            ->label('كلمات الاستبعاد')
                                            ->rows(2),
                                        Forms\Components\TextInput::make('sort_order')
                                            ->label('الترتيب')
                                            ->numeric()
                                            ->default(0),
                                        Forms\Components\Toggle::make('is_active')
                                            ->label('ظاهر')
                                            ->default(true),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Tabs\Tab::make('أيقونات البانر')
                            ->icon('heroicon-o-bolt')
                            ->schema([
                                Forms\Components\Placeholder::make('hl_hint')
                                    ->label('')
                                    ->content('الثلاث أيقونات تحت بانر «منتجات MG»: المشاريع · الصرف · مياه الشرب.'),
                                Forms\Components\Repeater::make('highlights')
                                    ->label('الأيقونات')
                                    ->collapsible()
                                    ->cloneable()
                                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['key'] ?? 'أيقونة')
                                    ->schema([
                                        Forms\Components\Hidden::make('id'),
                                        Forms\Components\TextInput::make('key')
                                            ->label('المفتاح')
                                            ->required()
                                            ->maxLength(64),
                                        Forms\Components\TextInput::make('label')
                                            ->label('الاسم الظاهر')
                                            ->required()
                                            ->maxLength(80)
                                            ->live(onBlur: true),
                                        Forms\Components\Select::make('icon')
                                            ->label('أيقونة')
                                            ->options([
                                                'projects' => 'مشاريع',
                                                'sewage' => 'صرف',
                                                'water' => 'مياه',
                                            ])
                                            ->searchable()
                                            ->native(false),
                                        Forms\Components\TextInput::make('deep_link')
                                            ->label('Deep link')
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('link')
                                            ->label('رابط بديل')
                                            ->maxLength(255),
                                        Forms\Components\Textarea::make('category_match')
                                            ->label('مطابقة التصنيف (سطر لكل كلمة)')
                                            ->rows(2),
                                        Forms\Components\Textarea::make('category_prefer')
                                            ->label('تفضيل')
                                            ->rows(2),
                                        Forms\Components\Textarea::make('category_exclude')
                                            ->label('استبعاد')
                                            ->rows(2),
                                        Forms\Components\TextInput::make('sort_order')
                                            ->label('الترتيب')
                                            ->numeric()
                                            ->default(0),
                                        Forms\Components\Toggle::make('is_active')
                                            ->label('ظاهر')
                                            ->default(true),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->persistTabInQueryString()
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $bannerImage = $data['banner_image'] ?? null;
        if (is_array($bannerImage)) {
            $bannerImage = array_values($bannerImage)[0] ?? null;
        }

        $settings = HomeMobileSetting::instance();
        $settings->fill([
            'banner_title' => $data['banner_title'] ?? 'منتجات MG',
            'banner_subtitle' => $data['banner_subtitle'] ?? null,
            'banner_cta_text' => $data['banner_cta_text'] ?? 'اكتشف المزيد',
            'banner_cta_link' => $data['banner_cta_link'] ?? null,
            'banner_image' => $bannerImage,
            'banner_is_active' => (bool) ($data['banner_is_active'] ?? true),
            'main_sections_title' => $data['main_sections_title'] ?? 'الأقسام الرئيسية',
            'new_products_title' => $data['new_products_title'] ?? 'جديد منتجات MG',
            'stores_title' => $data['stores_title'] ?? 'المتاجر',
        ])->save();

        $keptTypeIds = [];
        foreach ($data['product_types'] ?? [] as $i => $row) {
            $key = $this->normalizeKey((string) ($row['key'] ?? ''), 'type');
            $image = $row['image'] ?? null;
            if (is_array($image)) {
                $image = array_values($image)[0] ?? null;
            }

            $payload = [
                'key' => $key,
                'name' => (string) ($row['name'] ?? $key),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'icon' => (string) ($row['icon'] ?? $key),
                'image' => $image,
                'keywords' => $this->linesToArray($row['keywords'] ?? ''),
                'exclude_keywords' => $this->linesToArray($row['exclude_keywords'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? $i * 10),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];

            if (! empty($row['id'])) {
                $model = HomeProductType::query()->find($row['id']);
                if ($model) {
                    $model->update($payload);
                    $keptTypeIds[] = $model->id;
                    continue;
                }
            }

            $model = HomeProductType::query()->updateOrCreate(['key' => $key], $payload);
            $keptTypeIds[] = $model->id;
        }
        HomeProductType::query()->whereNotIn('id', $keptTypeIds)->delete();

        $keptHighlightIds = [];
        foreach ($data['highlights'] ?? [] as $i => $row) {
            $key = $this->normalizeKey((string) ($row['key'] ?? ''), 'hl');

            $payload = [
                'key' => $key,
                'label' => (string) ($row['label'] ?? $key),
                'icon' => (string) ($row['icon'] ?? $key),
                'link' => $row['link'] ?? null,
                'deep_link' => $row['deep_link'] ?? null,
                'category_match' => $this->linesToArray($row['category_match'] ?? ''),
                'category_prefer' => $this->linesToArray($row['category_prefer'] ?? ''),
                'category_exclude' => $this->linesToArray($row['category_exclude'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? $i * 10),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];

            if (! empty($row['id'])) {
                $model = HomeHighlight::query()->find($row['id']);
                if ($model) {
                    $model->update($payload);
                    $keptHighlightIds[] = $model->id;
                    continue;
                }
            }

            $model = HomeHighlight::query()->updateOrCreate(['key' => $key], $payload);
            $keptHighlightIds[] = $model->id;
        }
        HomeHighlight::query()->whereNotIn('id', $keptHighlightIds)->delete();

        app(HomeMobileLayoutService::class)->forgetCache();

        Notification::make()
            ->title('تم حفظ سكاشن الرئيسية')
            ->body('التغييرات تظهر فوراً عبر API التطبيق.')
            ->success()
            ->send();

        $this->mount();
    }

    protected function normalizeKey(string $raw, string $prefix): string
    {
        $key = strtolower(trim($raw));
        $key = (string) preg_replace('/[^a-z0-9_]+/', '_', $key);
        $key = trim($key, '_');

        return $key !== '' ? $key : $prefix.'_'.Str::lower(Str::random(6));
    }

    /** @return list<string> */
    protected function linesToArray(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }

        return collect(preg_split('/[\n,]+/', (string) $raw) ?: [])
            ->map(fn ($s) => trim((string) $s))
            ->filter()
            ->values()
            ->all();
    }

    /** Live preview props for the blade. */
    public function previewState(): array
    {
        $d = $this->data ?? [];

        return [
            'banner_title' => $d['banner_title'] ?? 'منتجات MG',
            'banner_subtitle' => $d['banner_subtitle'] ?? '',
            'banner_cta_text' => $d['banner_cta_text'] ?? 'اكتشف المزيد',
            'banner_is_active' => (bool) ($d['banner_is_active'] ?? true),
            'main_sections_title' => $d['main_sections_title'] ?? 'الأقسام الرئيسية',
            'new_products_title' => $d['new_products_title'] ?? 'جديد منتجات MG',
            'stores_title' => $d['stores_title'] ?? 'المتاجر',
            'product_types' => collect($d['product_types'] ?? [])
                ->filter(fn ($r) => ($r['is_active'] ?? true))
                ->take(4)
                ->values()
                ->all(),
            'highlights' => collect($d['highlights'] ?? [])
                ->filter(fn ($r) => ($r['is_active'] ?? true))
                ->take(3)
                ->values()
                ->all(),
        ];
    }
}

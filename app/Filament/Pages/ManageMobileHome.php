<?php

namespace App\Filament\Pages;

use App\Models\HomeHighlight;
use App\Models\HomeLayoutSection;
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

    public string $previewRole = 'plumber';

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
        return 'تحكم كاملة في رئيسية التطبيق';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function mount(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();
        $this->form->fill($this->loadFormState());
    }

    /** @return array<string, mixed> */
    protected function loadFormState(): array
    {
        $settings = HomeMobileSetting::instance();
        $data = $settings->only([
            'banner_title', 'banner_subtitle', 'banner_cta_text', 'banner_cta_link',
            'banner_image', 'banner_is_active',
            'main_sections_title', 'new_products_title', 'stores_title',
        ]);

        if (! empty($data['banner_image']) && is_string($data['banner_image'])) {
            $data['banner_image'] = [$data['banner_image']];
        }

        $typeOptions = collect(config('home_layout.types', []))
            ->mapWithKeys(fn ($meta, $type) => [$type => ($meta['label'] ?? $type).' ('.$type.')'])
            ->all();

        $role = $this->previewRole;
        $data['sections'] = HomeLayoutSection::query()->get()
            ->sortBy(fn (HomeLayoutSection $s) => [$s->sortForRole($role), $s->id])
            ->values()
            ->map(function (HomeLayoutSection $s) {
                $settings = is_array($s->settings) ? $s->settings : [];
                $items = is_array($s->items_override) ? $s->items_override : [];
                if (! array_is_list($items) && $items !== []) {
                    // Role-map → flatten first list for editor
                    $items = array_values($items)[0] ?? [];
                }

                return [
                    'id' => $s->id,
                    'key' => $s->key,
                    'type' => $s->type,
                    'title' => $s->title,
                    'is_active' => $s->is_active,
                    'sort_order' => $s->sort_order,
                    'sort_orders' => $s->sort_orders ?? [],
                    'roles' => $s->roles ?? [],
                    'limit' => $s->limit,
                    'see_all_text' => $s->see_all['text'] ?? null,
                    'see_all_link' => $s->see_all['link'] ?? null,
                    'setting_button_text' => $settings['button_text'] ?? null,
                    'setting_button_link' => $settings['button_link'] ?? null,
                    'setting_placeholder' => $settings['placeholder'] ?? null,
                    'setting_link' => $settings['link'] ?? null,
                    'setting_autoplay' => (bool) ($settings['autoplay'] ?? true),
                    'setting_interval_ms' => $settings['interval_ms'] ?? 4000,
                    'fixed_items' => array_values($items),
                ];
            })->values()->all();

        $data['_type_labels'] = $typeOptions;

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

        return $data;
    }

    public function form(Form $form): Form
    {
        $typeOptions = collect(config('home_layout.types', []))
            ->mapWithKeys(fn ($meta, $type) => [$type => ($meta['label'] ?? $type)])
            ->all();

        return $form
            ->schema([
                Forms\Components\Tabs::make('mobile_home')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('ترتيب الأقسام')
                            ->icon('heroicon-o-bars-3')
                            ->schema([
                                Forms\Components\Placeholder::make('sections_hint')
                                    ->label('')
                                    ->content('اسحب لإعادة الترتيب حسب الدور المحدد في معاينة الهاتف (يمين). كل دور له ترتيب مستقل مطابق لعينات التطبيق. عدّل الأزرار/الروابط من الحقول بدون JSON.'),
                                Forms\Components\Repeater::make('sections')
                                    ->label('أقسام الرئيسية')
                                    ->reorderable()
                                    ->collapsible()
                                    ->cloneable()
                                    ->itemLabel(function (array $state) use ($typeOptions): ?string {
                                        $type = $state['type'] ?? '';
                                        $label = $typeOptions[$type] ?? $type;
                                        $title = $state['title'] ?? null;
                                        $active = ($state['is_active'] ?? true) ? '' : ' · مخفي';

                                        return ($title ?: $label).' · '.($state['key'] ?? '').$active;
                                    })
                                    ->schema([
                                        Forms\Components\Hidden::make('id'),
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('key')
                                                ->label('المفتاح')
                                                ->required()
                                                ->maxLength(64)
                                                ->helperText('فريد — مثال: events أو promo_banners_2'),
                                            Forms\Components\Select::make('type')
                                                ->label('النوع')
                                                ->options($typeOptions)
                                                ->required()
                                                ->searchable()
                                                ->native(false),
                                            Forms\Components\Toggle::make('is_active')
                                                ->label('ظاهر')
                                                ->default(true)
                                                ->inline(false),
                                        ]),
                                        Forms\Components\TextInput::make('title')
                                            ->label('العنوان')
                                            ->maxLength(120)
                                            ->helperText('فارغ = العنوان الافتراضي في التطبيق · اكتب مسافة فارغة لإخفاء العنوان إن لزم'),
                                        Forms\Components\CheckboxList::make('roles')
                                            ->label('الأدوار التي ترى هذا القسم')
                                            ->options([
                                                'plumber' => 'سباك',
                                                'retail_trader' => 'تاجر قطاعي',
                                                'wholesale_distributor' => 'موزع جملة',
                                                'guest' => 'زائر',
                                            ])
                                            ->columns(4)
                                            ->required(),
                                        Forms\Components\Grid::make(3)->schema([
                                            Forms\Components\TextInput::make('limit')
                                                ->label('حد العناصر')
                                                ->numeric()
                                                ->minValue(1),
                                            Forms\Components\TextInput::make('see_all_text')
                                                ->label('نص «عرض المزيد»')
                                                ->maxLength(80),
                                            Forms\Components\TextInput::make('see_all_link')
                                                ->label('رابط عرض المزيد')
                                                ->maxLength(255)
                                                ->placeholder('mgplastic://list/events'),
                                        ]),
                                        Forms\Components\Section::make('إعدادات سريعة')
                                            ->collapsed()
                                            ->schema([
                                                Forms\Components\TextInput::make('setting_button_text')
                                                    ->label('نص زر المحفظة')
                                                    ->visible(fn (Forms\Get $get) => $get('type') === 'wallet'),
                                                Forms\Components\TextInput::make('setting_button_link')
                                                    ->label('رابط زر المحفظة')
                                                    ->visible(fn (Forms\Get $get) => $get('type') === 'wallet'),
                                                Forms\Components\TextInput::make('setting_placeholder')
                                                    ->label('نص البحث الافتراضي')
                                                    ->visible(fn (Forms\Get $get) => $get('type') === 'search'),
                                                Forms\Components\TextInput::make('setting_link')
                                                    ->label('رابط الضغط على القسم')
                                                    ->visible(fn (Forms\Get $get) => in_array($get('type'), ['reputation', 'points_summary'], true)),
                                                Forms\Components\Toggle::make('setting_autoplay')
                                                    ->label('تشغيل تلقائي للسلايدر')
                                                    ->visible(fn (Forms\Get $get) => $get('type') === 'slider'),
                                                Forms\Components\TextInput::make('setting_interval_ms')
                                                    ->label('مدة الشريحة (مللي ثانية)')
                                                    ->numeric()
                                                    ->visible(fn (Forms\Get $get) => $get('type') === 'slider'),
                                            ])
                                            ->columns(2),
                                        Forms\Components\Repeater::make('fixed_items')
                                            ->label('عناصر القسم (أزرار / روابط)')
                                            ->helperText('يظهر لأزرار السباك ولوحة تحكم المحل. اسحب لإعادة الترتيب.')
                                            ->visible(fn (Forms\Get $get) => in_array($get('type'), ['quick_actions', 'shop_quick_links'], true))
                                            ->collapsible()
                                            ->reorderable()
                                            ->cloneable()
                                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? $state['key'] ?? 'عنصر')
                                            ->schema([
                                                Forms\Components\TextInput::make('key')->label('المفتاح')->required()->maxLength(64),
                                                Forms\Components\TextInput::make('title')->label('العنوان')->required()->maxLength(120),
                                                Forms\Components\TextInput::make('subtitle')->label('فرعي')->maxLength(120),
                                                Forms\Components\Select::make('icon')
                                                    ->label('أيقونة')
                                                    ->options(collect(config('home_layout.icon_options', []))->mapWithKeys(fn ($i) => [$i => $i])->all())
                                                    ->searchable()
                                                    ->native(false),
                                                Forms\Components\TextInput::make('tint')->label('لون Hex')->placeholder('#0D80F2'),
                                                Forms\Components\Toggle::make('is_featured')->label('بطاقة كبيرة')->default(false),
                                                Forms\Components\TextInput::make('link')->label('الرابط (mgplastic://…)')->required()->columnSpanFull(),
                                            ])
                                            ->columns(3)
                                            ->columnSpanFull(),
                                        Forms\Components\Hidden::make('sort_order'),
                                        Forms\Components\Hidden::make('sort_orders'),
                                    ])
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Tabs\Tab::make('البانر الأخضر')
                            ->icon('heroicon-o-sparkles')
                            ->schema([
                                Forms\Components\Toggle::make('banner_is_active')
                                    ->label('إظهار البانر (mg_banner)')
                                    ->inline(false)
                                    ->live(),
                                Forms\Components\TextInput::make('banner_title')->label('العنوان')->required()->maxLength(120)->live(onBlur: true),
                                Forms\Components\TextInput::make('banner_subtitle')->label('النص الفرعي')->maxLength(200)->live(onBlur: true),
                                Forms\Components\TextInput::make('banner_cta_text')->label('نص الزر')->maxLength(80)->live(onBlur: true),
                                Forms\Components\TextInput::make('banner_cta_link')->label('رابط الزر')->maxLength(255),
                                Forms\Components\FileUpload::make('banner_image')
                                    ->label('صورة البانر')
                                    ->disk('public')
                                    ->directory('home-mobile')
                                    ->image()
                                    ->imageEditor()
                                    ->maxSize(4096),
                                Forms\Components\TextInput::make('main_sections_title')->label('عنوان الأقسام الرئيسية')->required()->live(onBlur: true),
                                Forms\Components\TextInput::make('new_products_title')->label('عنوان جديد منتجات MG')->required()->live(onBlur: true),
                                Forms\Components\TextInput::make('stores_title')->label('عنوان المتاجر')->required()->live(onBlur: true),
                            ])
                            ->columns(2),

                        Forms\Components\Tabs\Tab::make('أنواع القطع')
                            ->icon('heroicon-o-squares-2x2')
                            ->schema([
                                Forms\Components\Repeater::make('product_types')
                                    ->label('بطاقات الأقسام الرئيسية')
                                    ->collapsible()
                                    ->cloneable()
                                    ->reorderable()
                                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? $state['key'] ?? null)
                                    ->schema([
                                        Forms\Components\Hidden::make('id'),
                                        Forms\Components\TextInput::make('key')->label('المفتاح')->required()->maxLength(64),
                                        Forms\Components\TextInput::make('name')->label('الاسم')->required()->live(onBlur: true),
                                        Forms\Components\TextInput::make('subtitle')->label('فرعي')->live(onBlur: true),
                                        Forms\Components\Select::make('icon')->label('أيقونة')->options([
                                            'pipe' => 'ماسورة', 'tee' => 'تيه', 'valve' => 'محبس', 'elbow' => 'كوع', 'cap' => 'سداد',
                                        ])->native(false),
                                        Forms\Components\FileUpload::make('image')->label('صورة')->disk('public')->directory('product-types')->image(),
                                        Forms\Components\Textarea::make('keywords')->label('كلمات مطابقة')->rows(2),
                                        Forms\Components\Textarea::make('exclude_keywords')->label('استبعاد')->rows(2),
                                        Forms\Components\TextInput::make('sort_order')->label('ترتيب')->numeric()->default(0),
                                        Forms\Components\Toggle::make('is_active')->label('ظاهر')->default(true),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Tabs\Tab::make('أيقونات البانر')
                            ->icon('heroicon-o-bolt')
                            ->schema([
                                Forms\Components\Repeater::make('highlights')
                                    ->label('أيقونات تحت البانر الأخضر')
                                    ->collapsible()
                                    ->cloneable()
                                    ->reorderable()
                                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['key'] ?? null)
                                    ->schema([
                                        Forms\Components\Hidden::make('id'),
                                        Forms\Components\TextInput::make('key')->label('المفتاح')->required(),
                                        Forms\Components\TextInput::make('label')->label('الاسم')->required()->live(onBlur: true),
                                        Forms\Components\Select::make('icon')->label('أيقونة')->options([
                                            'projects' => 'مشاريع', 'sewage' => 'صرف', 'water' => 'مياه',
                                        ])->native(false),
                                        Forms\Components\TextInput::make('deep_link')->label('Deep link'),
                                        Forms\Components\TextInput::make('link')->label('رابط'),
                                        Forms\Components\Textarea::make('category_match')->label('مطابقة تصنيف')->rows(2),
                                        Forms\Components\Textarea::make('category_prefer')->label('تفضيل')->rows(2),
                                        Forms\Components\Textarea::make('category_exclude')->label('استبعاد')->rows(2),
                                        Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                                        Forms\Components\Toggle::make('is_active')->default(true),
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

    public function setPreviewRole(string $role): void
    {
        if (! in_array($role, HomeMobileLayoutService::ROLES, true)) {
            return;
        }

        // Persist current drag order into the role we are leaving.
        $this->applyRepeaterOrderToSortOrders($this->previewRole);

        $this->previewRole = $role;

        $sections = collect($this->data['sections'] ?? [])
            ->sortBy(function (array $row) use ($role) {
                $orders = is_array($row['sort_orders'] ?? null) ? $row['sort_orders'] : [];

                return [(int) ($orders[$role] ?? $row['sort_order'] ?? 9999), (string) ($row['key'] ?? '')];
            })
            ->values()
            ->all();

        $this->data['sections'] = $sections;
        $this->form->fill($this->data);
    }

    /**
     * Write the current repeater order into sort_orders[role] for sections that include that role.
     */
    protected function applyRepeaterOrderToSortOrders(string $role): void
    {
        $idx = 0;
        foreach (array_values($this->data['sections'] ?? []) as $i => $row) {
            $roles = array_values(array_filter($row['roles'] ?? []));
            $orders = is_array($row['sort_orders'] ?? null) ? $row['sort_orders'] : [];
            if (in_array($role, $roles, true)) {
                $orders[$role] = $idx * 10;
                $idx++;
            }
            $this->data['sections'][$i]['sort_orders'] = $orders;
            $this->data['sections'][$i]['sort_order'] = $i * 10;
        }
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $this->data['sections'] = array_values($data['sections'] ?? []);
        $this->applyRepeaterOrderToSortOrders($this->previewRole);
        $data['sections'] = $this->data['sections'];

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

        $keptSectionIds = [];
        foreach (array_values($data['sections'] ?? []) as $i => $row) {
            $key = $this->normalizeKey((string) ($row['key'] ?? ''), 'section');
            $seeAll = null;
            if (filled($row['see_all_link'] ?? null)) {
                $seeAll = [
                    'text' => (string) ($row['see_all_text'] ?? 'عرض المزيد'),
                    'link' => (string) $row['see_all_link'],
                ];
            }

            $sortOrders = is_array($row['sort_orders'] ?? null) ? $row['sort_orders'] : [];
            // Merge any existing DB orders so other roles are not wiped if missing from form state
            if (! empty($row['id']) && ($existing = HomeLayoutSection::query()->find($row['id']))) {
                $sortOrders = array_merge(
                    is_array($existing->sort_orders) ? $existing->sort_orders : [],
                    $sortOrders,
                );
            }

            $payload = [
                'key' => $key,
                'type' => (string) ($row['type'] ?? 'search'),
                'title' => array_key_exists('title', $row) ? $row['title'] : null,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => $i * 10,
                'sort_orders' => $sortOrders,
                'roles' => array_values(array_filter($row['roles'] ?? [])),
                'limit' => filled($row['limit'] ?? null) ? (int) $row['limit'] : null,
                'see_all' => $seeAll,
                'settings' => $this->buildSettingsFromRow($row),
                'items_override' => $this->buildFixedItemsFromRow($row),
            ];

            if (! empty($row['id']) && ($model = HomeLayoutSection::query()->find($row['id']))) {
                $model->update($payload);
                $keptSectionIds[] = $model->id;
            } else {
                $model = HomeLayoutSection::query()->updateOrCreate(['key' => $key], $payload);
                $keptSectionIds[] = $model->id;
            }
        }
        HomeLayoutSection::query()->whereNotIn('id', $keptSectionIds ?: [0])->delete();

        $keptTypeIds = [];
        foreach (array_values($data['product_types'] ?? []) as $i => $row) {
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
            if (! empty($row['id']) && ($model = HomeProductType::query()->find($row['id']))) {
                $model->update($payload);
                $keptTypeIds[] = $model->id;
            } else {
                $keptTypeIds[] = HomeProductType::query()->updateOrCreate(['key' => $key], $payload)->id;
            }
        }
        HomeProductType::query()->whereNotIn('id', $keptTypeIds ?: [0])->delete();

        $keptHighlightIds = [];
        foreach (array_values($data['highlights'] ?? []) as $i => $row) {
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
            if (! empty($row['id']) && ($model = HomeHighlight::query()->find($row['id']))) {
                $model->update($payload);
                $keptHighlightIds[] = $model->id;
            } else {
                $keptHighlightIds[] = HomeHighlight::query()->updateOrCreate(['key' => $key], $payload)->id;
            }
        }
        HomeHighlight::query()->whereNotIn('id', $keptHighlightIds ?: [0])->delete();

        $version = app(HomeMobileLayoutService::class)->bumpVersion();

        Notification::make()
            ->title('تم نشر تخطيط الرئيسية')
            ->body('version = '.$version.' — التطبيق يستلم الترتيب الجديد من /home/layout')
            ->success()
            ->send();

        $this->form->fill($this->loadFormState());
    }

    /** @return array<string, mixed> */
    public function previewState(): array
    {
        $sections = collect($this->data['sections'] ?? [])
            ->filter(fn ($s) => ($s['is_active'] ?? true) && in_array($this->previewRole, $s['roles'] ?? [], true))
            ->values()
            ->all();

        return [
            'role' => $this->previewRole,
            'banner_title' => $this->data['banner_title'] ?? 'منتجات MG',
            'banner_subtitle' => $this->data['banner_subtitle'] ?? '',
            'banner_cta_text' => $this->data['banner_cta_text'] ?? 'اكتشف المزيد',
            'banner_is_active' => (bool) ($this->data['banner_is_active'] ?? true),
            'sections' => $sections,
            'version' => (int) (HomeMobileSetting::instance()->layout_version ?? 1),
        ];
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

    /** @param  array<string, mixed>  $row */
    protected function buildSettingsFromRow(array $row): array
    {
        $type = (string) ($row['type'] ?? '');

        return match ($type) {
            'wallet' => array_filter([
                'button_text' => $row['setting_button_text'] ?? 'عرض المحفظة',
                'button_link' => $row['setting_button_link'] ?? 'mgplastic://wallet',
            ], fn ($v) => filled($v)),
            'search' => array_filter([
                'placeholder' => $row['setting_placeholder'] ?? 'ابحث عن المنتج أو المقاس أو الكود...',
            ], fn ($v) => filled($v)),
            'slider' => [
                'autoplay' => (bool) ($row['setting_autoplay'] ?? true),
                'interval_ms' => (int) ($row['setting_interval_ms'] ?? 4000),
            ],
            'reputation', 'points_summary' => array_filter([
                'link' => $row['setting_link'] ?? null,
            ], fn ($v) => filled($v)),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>|null
     */
    protected function buildFixedItemsFromRow(array $row): ?array
    {
        if (! in_array($row['type'] ?? '', ['quick_actions', 'shop_quick_links'], true)) {
            return null;
        }

        $items = [];
        foreach ($row['fixed_items'] ?? [] as $item) {
            if (! filled($item['key'] ?? null) || ! filled($item['title'] ?? null)) {
                continue;
            }
            $rowItem = [
                'key' => (string) $item['key'],
                'title' => (string) $item['title'],
                'icon' => (string) ($item['icon'] ?? 'grid'),
                'link' => (string) ($item['link'] ?? 'mgplastic://home'),
            ];
            if (array_key_exists('subtitle', $item)) {
                $rowItem['subtitle'] = $item['subtitle'];
            }
            if (($row['type'] ?? '') === 'shop_quick_links') {
                $rowItem['tint'] = $item['tint'] ?? '#0D80F2';
                $rowItem['is_featured'] = (bool) ($item['is_featured'] ?? false);
            }
            $items[] = $rowItem;
        }

        return $items !== [] ? $items : null;
    }

    public function resetToSampleDefaults(): void
    {
        $count = app(HomeMobileLayoutService::class)->syncDefaultSections(force: true);

        Notification::make()
            ->title('تمت استعادة الترتيب الافتراضي')
            ->body("أُعيد زرع {$count} قسماً مطابقاً لعينات التطبيق (sample-*.json).")
            ->success()
            ->send();

        $this->form->fill($this->loadFormState());
    }
}

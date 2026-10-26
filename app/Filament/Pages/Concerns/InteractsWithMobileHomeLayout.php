<?php

namespace App\Filament\Pages\Concerns;

use App\Models\HomeHighlight;
use App\Models\HomeLayoutSection;
use App\Models\HomeMobileSetting;
use App\Models\HomeProductType;
use App\Services\HomeMobileLayoutService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

trait InteractsWithMobileHomeLayout
{
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(\App\Support\AdminPermissions::USERS_MANAGE);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'رئيسية التطبيق';
    }

    /** @return array<string, string> */
    protected function typeOptions(): array
    {
        return collect(config('home_layout.types', []))
            ->mapWithKeys(fn ($meta, $type) => [$type => ($meta['label'] ?? $type)])
            ->all();
    }

    /** @return array<string, mixed> */
    protected function mapSectionRow(HomeLayoutSection $s): array
    {
        $settings = is_array($s->settings) ? $s->settings : [];
        $items = is_array($s->items_override) ? $s->items_override : [];
        if (! array_is_list($items) && $items !== []) {
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
    }

    /** @return list<array<string, mixed>> */
    protected function sectionsForRole(string $role): array
    {
        return HomeLayoutSection::query()
            ->get()
            ->filter(fn (HomeLayoutSection $s) => in_array($role, $s->roles ?? [], true))
            ->sortBy(fn (HomeLayoutSection $s) => [$s->sortForRole($role), $s->id])
            ->values()
            ->map(fn (HomeLayoutSection $s) => $this->mapSectionRow($s))
            ->all();
    }

    /** Types whose content comes from the app — no limit / see_all. */
    protected function appSectionTypes(): array
    {
        return ['pending_receipts', 'wallet', 'search', 'low_stock', 'reputation', 'points_summary'];
    }

    /**
     * Section editor for one audience — no roles checklist (the page owns the role).
     *
     * @param  list<string>  $fixedItemTypes
     */
    protected function audienceSectionsRepeater(string $statePath, string $label, array $fixedItemTypes = ['quick_actions', 'shop_quick_links']): Forms\Components\Repeater
    {
        $typeOptions = $this->typeOptions();
        $appTypes = $this->appSectionTypes();

        return Forms\Components\Repeater::make($statePath)
            ->label($label)
            ->reorderable()
            ->collapsible()
            ->collapsed()
            ->cloneable()
            ->itemLabel(function (array $state) use ($typeOptions): ?string {
                $type = $state['type'] ?? '';
                $label = $typeOptions[$type] ?? $type;
                $title = filled($state['title'] ?? null) ? $state['title'] : null;
                $active = ($state['is_active'] ?? true) ? '' : ' · مخفي';
                $extra = match ($type) {
                    'search' => filled($state['setting_placeholder'] ?? null) ? ' · '.$state['setting_placeholder'] : '',
                    'wallet' => filled($state['setting_button_text'] ?? null) ? ' · '.$state['setting_button_text'] : '',
                    'quick_actions', 'shop_quick_links' => ' · '.count($state['fixed_items'] ?? []).' عنصر',
                    default => filled($state['limit'] ?? null) ? ' · حد '.$state['limit'] : '',
                };

                return ($title ?: $label).' · '.($state['key'] ?? '').$extra.$active;
            })
            ->schema([
                Forms\Components\Hidden::make('id'),
                Forms\Components\Hidden::make('sort_order'),
                Forms\Components\Hidden::make('sort_orders'),
                Forms\Components\Hidden::make('roles'),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('key')
                        ->label('المفتاح')
                        ->required()
                        ->maxLength(64)
                        ->disabled()
                        ->dehydrated(),
                    Forms\Components\Select::make('type')
                        ->label('النوع')
                        ->options($typeOptions)
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->disabled(fn (Forms\Get $get) => filled($get('id')))
                        ->dehydrated(),
                    Forms\Components\Toggle::make('is_active')
                        ->label('ظاهر')
                        ->default(true)
                        ->inline(false),
                ]),

                // —— Search settings (visible, not buried) ——
                Forms\Components\TextInput::make('setting_placeholder')
                    ->label('نص مربع البحث (placeholder)')
                    ->helperText('هذا اللي يظهر داخل خانة البحث في التطبيق')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'search')
                    ->columnSpanFull(),

                // —— Wallet settings ——
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('setting_button_text')->label('نص زر المحفظة'),
                    Forms\Components\TextInput::make('setting_button_link')->label('رابط زر المحفظة')->placeholder('mgplastic://wallet'),
                ])->visible(fn (Forms\Get $get) => $get('type') === 'wallet'),

                // —— Reputation / points ——
                Forms\Components\TextInput::make('setting_link')
                    ->label('رابط الضغط على القسم')
                    ->visible(fn (Forms\Get $get) => in_array($get('type'), ['reputation', 'points_summary'], true))
                    ->columnSpanFull(),

                // —— Slider ——
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\Toggle::make('setting_autoplay')->label('تشغيل تلقائي')->inline(false),
                    Forms\Components\TextInput::make('setting_interval_ms')->label('مدة الشريحة (ms)')->numeric(),
                ])->visible(fn (Forms\Get $get) => $get('type') === 'slider'),

                // —— Title / limit / see_all for content sections ——
                Forms\Components\TextInput::make('title')
                    ->label('العنوان')
                    ->maxLength(120)
                    ->helperText('فارغ = العنوان الافتراضي في التطبيق')
                    ->visible(fn (Forms\Get $get) => ! in_array($get('type'), ['search', 'wallet', 'pending_receipts'], true)),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('limit')->label('حد العناصر')->numeric()->minValue(1),
                    Forms\Components\TextInput::make('see_all_text')->label('نص «عرض المزيد»')->maxLength(80),
                    Forms\Components\TextInput::make('see_all_link')->label('رابط عرض المزيد')->maxLength(255)->placeholder('mgplastic://…'),
                ])->visible(fn (Forms\Get $get) => ! in_array($get('type'), $appTypes, true)
                    && ! in_array($get('type'), ['quick_actions', 'shop_quick_links', 'mg_banner', 'slider'], true)),

                Forms\Components\Placeholder::make('app_source_note')
                    ->label('')
                    ->content('هذا القسم بياناته من التطبيق (رصيد / مخزون / …) — الباك إند يتحكم في الإظهار والترتيب فقط.')
                    ->visible(fn (Forms\Get $get) => in_array($get('type'), ['pending_receipts', 'low_stock', 'points_summary'], true)),

                Forms\Components\Placeholder::make('reputation_note')
                    ->label('')
                    ->content('لكل سباك: درجته + المستوى (برونزي/فضي/…) + النجوم + شارته الخاصة + سلسلة الأسابيع. تُرسل داخل items عند تسجيل الدخول. ضبط المستويات والشارات من: نظام النقاط → إعدادات السمعة والضمان.')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'reputation'),

                Forms\Components\Repeater::make('fixed_items')
                    ->label('الأزرار / الروابط')
                    ->helperText('عنوان، أيقونة، لون، رابط — بدون JSON')
                    ->visible(fn (Forms\Get $get) => in_array($get('type'), $fixedItemTypes, true))
                    ->collapsible()
                    ->reorderable()
                    ->cloneable()
                    ->defaultItems(0)
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
                        Forms\Components\TextInput::make('link')->label('الرابط')->required()->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ])
            ->columnSpanFull();
    }

    /**
     * Upsert sections for one role without deleting other audiences' sections.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function saveSectionsForRole(string $role, array $rows): void
    {
        $touchedIds = [];
        $idx = 0;

        foreach (array_values($rows) as $row) {
            $key = $this->normalizeKey((string) ($row['key'] ?? ''), 'section');
            $seeAll = null;
            if (filled($row['see_all_link'] ?? null)) {
                $seeAll = [
                    'text' => (string) ($row['see_all_text'] ?? 'عرض المزيد'),
                    'link' => (string) $row['see_all_link'],
                ];
            }

            $existing = ! empty($row['id'])
                ? HomeLayoutSection::query()->find($row['id'])
                : HomeLayoutSection::query()->where('key', $key)->first();

            $roles = array_values(array_unique(array_filter(array_merge(
                is_array($existing?->roles) ? $existing->roles : [],
                [$role],
            ))));

            $sortOrders = is_array($existing?->sort_orders) ? $existing->sort_orders : [];
            if (is_array($row['sort_orders'] ?? null)) {
                $sortOrders = array_merge($sortOrders, $row['sort_orders']);
            }
            $sortOrders[$role] = $idx * 10;

            $payload = [
                'key' => $key,
                'type' => (string) ($row['type'] ?? 'search'),
                'title' => array_key_exists('title', $row) ? $row['title'] : null,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => (int) ($sortOrders['plumber'] ?? $idx * 10),
                'sort_orders' => $sortOrders,
                'roles' => $roles,
                'limit' => filled($row['limit'] ?? null) ? (int) $row['limit'] : null,
                'see_all' => $seeAll,
                'settings' => $this->buildSettingsFromRow($row),
                'items_override' => $this->buildFixedItemsFromRow($row),
            ];

            if ($existing) {
                $existing->update($payload);
                $touchedIds[] = $existing->id;
            } else {
                $touchedIds[] = HomeLayoutSection::query()->create($payload)->id;
            }
            $idx++;
        }

        // Detach this role from sections removed in the editor
        HomeLayoutSection::query()
            ->whereJsonContains('roles', $role)
            ->when($touchedIds !== [], fn ($q) => $q->whereNotIn('id', $touchedIds))
            ->when($touchedIds === [], fn ($q) => $q)
            ->get()
            ->each(function (HomeLayoutSection $section) use ($role, $touchedIds) {
                if (in_array($section->id, $touchedIds, true)) {
                    return;
                }
                $roles = array_values(array_filter(
                    $section->roles ?? [],
                    fn ($r) => $r !== $role
                ));
                $orders = is_array($section->sort_orders) ? $section->sort_orders : [];
                unset($orders[$role]);

                if ($roles === []) {
                    $section->delete();
                } else {
                    $section->update(['roles' => $roles, 'sort_orders' => $orders]);
                }
            });
    }

    protected function bumpAndNotify(string $title): void
    {
        $version = app(HomeMobileLayoutService::class)->bumpVersion();
        app(HomeMobileLayoutService::class)->forgetCache();

        Notification::make()
            ->title($title)
            ->body('version = '.$version.' — GET /api/v1/mobile/home/layout')
            ->success()
            ->send();
    }

    public function resetToSampleDefaults(): void
    {
        $count = app(HomeMobileLayoutService::class)->syncDefaultSections(force: true);

        Notification::make()
            ->title('تمت استعادة الترتيب الافتراضي')
            ->body("أُعيد زرع {$count} قسماً من عينات التطبيق.")
            ->success()
            ->send();

        $this->form->fill($this->loadFormState());
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

    /** @return array<string, mixed> */
    protected function sharedSettingsState(): array
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

        return $data;
    }

    /** @return list<array<string, mixed>> */
    protected function productTypesState(): array
    {
        return HomeProductType::query()->ordered()->get()->map(function (HomeProductType $t) {
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
    }

    /** @return list<array<string, mixed>> */
    protected function highlightsState(): array
    {
        return HomeHighlight::query()->ordered()->get()->map(function (HomeHighlight $h) {
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
    }

    /** @param  array<string, mixed>  $data */
    protected function saveSharedSettings(array $data): void
    {
        $bannerImage = $data['banner_image'] ?? null;
        if (is_array($bannerImage)) {
            $bannerImage = array_values($bannerImage)[0] ?? null;
        }

        HomeMobileSetting::instance()->fill([
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
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function saveProductTypes(array $rows): void
    {
        $kept = [];
        foreach (array_values($rows) as $i => $row) {
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
                $kept[] = $model->id;
            } else {
                $kept[] = HomeProductType::query()->updateOrCreate(['key' => $key], $payload)->id;
            }
        }
        HomeProductType::query()->whereNotIn('id', $kept ?: [0])->delete();
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function saveHighlights(array $rows): void
    {
        $kept = [];
        foreach (array_values($rows) as $i => $row) {
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
                $kept[] = $model->id;
            } else {
                $kept[] = HomeHighlight::query()->updateOrCreate(['key' => $key], $payload)->id;
            }
        }
        HomeHighlight::query()->whereNotIn('id', $kept ?: [0])->delete();
    }

    /** @return array<string, mixed> */
    public function previewStateFor(string $role, string $sectionsPath = 'sections'): array
    {
        $sections = collect($this->data[$sectionsPath] ?? [])
            ->filter(fn ($s) => ($s['is_active'] ?? true))
            ->values()
            ->all();

        $settings = HomeMobileSetting::instance();

        return [
            'role' => $role,
            'banner_title' => $this->data['banner_title'] ?? $settings->banner_title ?? 'منتجات MG',
            'banner_subtitle' => $this->data['banner_subtitle'] ?? $settings->banner_subtitle ?? '',
            'banner_cta_text' => $this->data['banner_cta_text'] ?? $settings->banner_cta_text ?? 'اكتشف المزيد',
            'banner_is_active' => (bool) ($this->data['banner_is_active'] ?? $settings->banner_is_active ?? true),
            'sections' => $sections,
            'version' => (int) ($settings->layout_version ?? 1),
        ];
    }
}

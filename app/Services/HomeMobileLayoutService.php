<?php

namespace App\Services;

use App\Models\Event;
use App\Models\HomeHighlight;
use App\Models\HomeLayoutSection;
use App\Models\HomeMobileSetting;
use App\Models\HomeProductType;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use App\Models\ProductPromotion;
use App\Models\PromoBanner;
use App\Models\Slider;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\PublicStorageUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class HomeMobileLayoutService
{
    public const ROLES = ['plumber', 'retail_trader', 'wholesale_distributor', 'guest'];

    public function ensureSeeded(): void
    {
        if (! Schema::hasTable('home_mobile_settings')) {
            return;
        }

        HomeMobileSetting::instance();
        $this->seedProductTypes();
        $this->seedHighlights();
        $this->seedSections();
    }

    public function forgetCache(): void
    {
        Cache::forget('home_mobile:product_type_defs');
        Cache::forget('home_mobile:highlight_defs');
        foreach (self::ROLES as $role) {
            Cache::forget('home_mobile:layout:'.$role);
        }
        Cache::forget('home_mobile:layout');
    }

    public function bumpVersion(): int
    {
        $settings = HomeMobileSetting::instance();
        $settings->layout_version = ((int) ($settings->layout_version ?? 1)) + 1;
        $settings->save();
        $this->forgetCache();

        return (int) $settings->layout_version;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function productTypeDefinitions(): array
    {
        $this->ensureSeeded();

        if (! Schema::hasTable('home_product_types')) {
            return app(ProductTypeService::class)->definitionsFromConfigOnly();
        }

        return Cache::remember('home_mobile:product_type_defs', 60, function () {
            $rows = HomeProductType::query()->active()->ordered()->get();
            if ($rows->isEmpty()) {
                return app(ProductTypeService::class)->definitionsFromConfigOnly();
            }

            return $rows->map(fn (HomeProductType $t) => $t->toDefinition())->values()->all();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function highlightDefinitions(): array
    {
        $this->ensureSeeded();

        if (! Schema::hasTable('home_highlights')) {
            return array_values(array_filter(config('home_highlights', []), 'is_array'));
        }

        return Cache::remember('home_mobile:highlight_defs', 60, function () {
            $rows = HomeHighlight::query()->active()->ordered()->get();
            if ($rows->isEmpty()) {
                return array_values(array_filter(config('home_highlights', []), 'is_array'));
            }

            return $rows->map(fn (HomeHighlight $h) => $h->toConfigRow())->values()->all();
        });
    }

    /**
     * Full layout for a role. Guest when unauthenticated.
     *
     * @return array<string, mixed>
     */
    public function layoutPayload(?string $role = null, ?User $user = null): array
    {
        $this->ensureSeeded();
        $role = $this->normalizeRole($role, $user);

        return Cache::remember('home_mobile:layout:'.$role, 60, function () use ($role, $user) {
            $settings = HomeMobileSetting::instance();
            $legacy = $settings->toApiLayout();
            $productTypes = app(ProductTypeService::class)->catalogForHome();
            $highlights = $this->mapHighlightsForApi();

            $sections = $this->buildSectionsForRole($role, $settings, $productTypes, $highlights, $user);

            return [
                'version' => (int) ($settings->layout_version ?? 1),
                'role' => $role,
                'sections' => $sections,
                // Backward-compatible top-level fields
                'banner' => $legacy['banner'],
                'titles' => $legacy['titles'],
                'product_types' => $productTypes,
                'highlights' => $highlights,
                'highlights_endpoint' => url('/api/v1/mobile/home/highlights'),
                'product_types_endpoints' => [
                    'trader' => url('/api/v1/mobile/trader/products/types'),
                    'distributor' => url('/api/v1/mobile/distributor/products/types'),
                ],
            ];
        });
    }

    public function normalizeRole(?string $role, ?User $user = null): string
    {
        if ($user) {
            if ($user->isPlumber()) {
                return 'plumber';
            }
            if ($user->isRetailTrader()) {
                return 'retail_trader';
            }
            if ($user->isWholesaleDistributor()) {
                return 'wholesale_distributor';
            }
        }

        $role = strtolower(trim((string) $role));

        return in_array($role, self::ROLES, true) ? $role : 'guest';
    }

    /**
     * @param  list<array<string, mixed>>  $productTypes
     * @param  list<array<string, mixed>>  $highlights
     * @return list<array<string, mixed>>
     */
    protected function buildSectionsForRole(
        string $role,
        HomeMobileSetting $settings,
        array $productTypes,
        array $highlights,
        ?User $user = null,
    ): array {
        if (! Schema::hasTable('home_layout_sections')) {
            return [];
        }

        $rows = HomeLayoutSection::query()
            ->active()
            ->forRole($role)
            ->get()
            ->sortBy(fn (HomeLayoutSection $row) => [$row->sortForRole($role), $row->id])
            ->values();

        $out = [];
        foreach ($rows as $row) {
            $section = $this->mapSection($row, $role, $settings, $productTypes, $highlights, $user);
            if ($section === null) {
                continue;
            }
            $out[] = $section;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $productTypes
     * @param  list<array<string, mixed>>  $highlights
     * @return array<string, mixed>|null
     */
    protected function mapSection(
        HomeLayoutSection $row,
        string $role,
        HomeMobileSetting $settings,
        array $productTypes,
        array $highlights,
        ?User $user = null,
    ): ?array {
        $type = (string) $row->type;
        $source = $row->source();
        $limit = $row->limit;

        $title = $row->title;
        if ($type === 'main_sections' && ($title === null || $title === '')) {
            $title = $settings->main_sections_title;
        }
        if ($type === 'new_products' && ($title === null || $title === '')) {
            $title = $settings->new_products_title;
        }
        if ($type === 'stores' && ($title === null || $title === '')) {
            $title = $settings->stores_title;
        }

        $settingsPayload = is_array($row->settings) ? $row->settings : [];
        $seeAll = $this->normalizeSeeAll($row->see_all);

        if ($type === 'mg_banner') {
            if (! $settings->banner_is_active) {
                return null;
            }
            $settingsPayload = array_merge([
                'title' => $settings->banner_title,
                'subtitle' => $settings->banner_subtitle,
                'cta_text' => $settings->banner_cta_text,
                'cta_link' => $settings->banner_cta_link,
                'image_url' => $settings->bannerImageUrl(),
            ], $settingsPayload);
        }

        $items = null;
        if ($source === 'app') {
            $items = null;
        } elseif ($resolved = $this->resolveItemsOverride($row->items_override, $role)) {
            $items = $resolved;
            if ($limit) {
                $items = array_slice($items, 0, $limit);
            }
        } else {
            $items = $this->loadItems($type, $role, $limit, $productTypes, $highlights, $settings, $row->key);
            if ($items === [] && in_array($type, [
                'slider', 'pipe_systems', 'main_sections', 'new_products', 'collections',
                'featured_wholesalers', 'events', 'stores', 'promo_banners', 'testimonials', 'plumbers',
            ], true)) {
                // Spec: empty API sections are hidden
                return null;
            }
        }

        return [
            'key' => (string) $row->key,
            'type' => $type,
            'title' => $title,
            'is_active' => true,
            'limit' => $limit,
            'see_all' => $seeAll,
            'settings' => (object) $settingsPayload,
            'items' => $items,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $productTypes
     * @param  list<array<string, mixed>>  $highlights
     * @return list<array<string, mixed>>|null
     */
    /**
     * @param  list<array<string, mixed>>|array<string, list<array<string, mixed>>>|null  $override
     * @return list<array<string, mixed>>|null
     */
    protected function resolveItemsOverride(mixed $override, string $role): ?array
    {
        if (! is_array($override) || $override === []) {
            return null;
        }

        // Role-keyed map: { retail_trader: [...], wholesale_distributor: [...] }
        if (isset($override[$role]) && is_array($override[$role])) {
            return array_values($override[$role]);
        }

        // Flat list of items (has numeric keys / item "key" field)
        $isList = array_is_list($override) || isset($override[0]);
        if ($isList) {
            return array_values($override);
        }

        return null;
    }

    protected function loadItems(
        string $type,
        string $role,
        ?int $limit,
        array $productTypes,
        array $highlights,
        HomeMobileSetting $settings,
        string $sectionKey = '',
    ): ?array {
        $limit = $limit ?: null;

        return match ($type) {
            'slider' => $this->itemsSlider($limit),
            'shop_quick_links' => $this->itemsShopQuickLinks($role, $limit, $sectionKey),
            'quick_actions' => $this->itemsQuickActions($limit),
            'pipe_systems' => $this->itemsPipeSystems($limit),
            'main_sections' => $this->itemsMainSections($productTypes, $limit),
            'new_products' => $this->itemsNewProducts($limit),
            'collections' => $this->itemsCollections($limit),
            'featured_wholesalers' => $this->itemsFeaturedWholesalers($limit),
            'events' => $this->itemsEvents($limit),
            'stores' => $this->itemsStores($limit),
            'mg_banner' => $this->itemsMgBannerIcons($highlights),
            'promo_banners' => $this->itemsPromoBanners($limit),
            'testimonials' => $this->itemsTestimonials($limit),
            'plumbers' => $this->itemsPlumbers($limit),
            'search' => null,
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    protected function itemsSlider(?int $limit): array
    {
        return Slider::query()
            ->active()
            ->forHome()
            ->ordered()
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->filter(fn (Slider $s) => $s->hasValidImage())
            ->map(fn (Slider $s) => [
                'id' => $s->id,
                'title' => $s->title ?: null,
                'image_url' => $s->image_url,
                'link' => filled($s->cta_primary_url) ? $s->cta_primary_url : null,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function itemsShopQuickLinks(string $role, ?int $limit, string $sectionKey = ''): array
    {
        $configKey = ($sectionKey === 'shop_quick_links_distributor' || $role === 'wholesale_distributor')
            ? 'shop_quick_links_distributor'
            : 'shop_quick_links_trader';
        $items = array_values(config('home_layout.'.$configKey, []));
        if ($limit) {
            $items = array_slice($items, 0, $limit);
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    protected function itemsQuickActions(?int $limit): array
    {
        $items = array_values(config('home_layout.quick_actions_plumber', []));
        if ($limit) {
            $items = array_slice($items, 0, $limit);
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    protected function itemsPipeSystems(?int $limit): array
    {
        $cats = ProductCategory::query()
            ->with(['translations', 'children'])
            ->withCount('products')
            ->whereNull('parent_id')
            ->orderBy('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();

        return $cats->map(function (ProductCategory $c) {
            $name = localized_name($c, 'name', $c->slug ?? 'نظام');
            $count = (int) $c->products_count;
            foreach ($c->children as $child) {
                $count += (int) ($child->products()->count());
            }

            return [
                'id' => $c->id,
                'title' => $name,
                'subtitle' => $c->translate('ar')?->description
                    ?? $c->translate('en')?->description
                    ?? null,
                'badge' => $c->systemBadge(),
                'image_url' => $c->resolvedImageUrl(),
                'products_count' => $count,
                'link' => 'mgplastic://category/'.$c->id,
            ];
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $productTypes
     * @return list<array<string, mixed>>
     */
    protected function itemsMainSections(array $productTypes, ?int $limit): array
    {
        $items = array_map(function (array $t) {
            return [
                'key' => $t['key'],
                'title' => $t['name'],
                'subtitle' => $t['subtitle'] ?? null,
                'icon' => $t['icon'] ?? $t['key'],
                'image_url' => $t['image_url'] ?? null,
                'products_count' => (int) ($t['products_count'] ?? 0),
                'link' => 'mgplastic://product_type/'.$t['key'],
            ];
        }, $productTypes);

        if ($limit) {
            $items = array_slice($items, 0, $limit);
        }

        return array_values($items);
    }

    /** @return list<array<string, mixed>> */
    protected function itemsNewProducts(?int $limit): array
    {
        return ProductPromotion::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (ProductPromotion $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'subtitle' => $p->subtitle,
                'media_type' => $p->media_type ?: 'image',
                'image_url' => $p->imageUrl(),
                'video_url' => $p->video_url,
                'link' => 'mgplastic://promotion/'.$p->id,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function itemsCollections(?int $limit): array
    {
        return ProductCollection::query()
            ->where('is_active', true)
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (ProductCollection $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'label' => $c->kindLabel(),
                'image_url' => $c->coverImageUrl(),
                'products_count' => (int) $c->products_count,
                'link' => 'mgplastic://collection/'.$c->id,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function itemsFeaturedWholesalers(?int $limit): array
    {
        return User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_featured_wholesaler', true)
            ->where('is_approved', true)
            ->where('is_active', true)
            ->orderBy('featured_sort')
            ->orderBy('brand_name')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'title' => $u->brand_name ?: $u->name,
                'image_url' => $u->storeBrandImageUrl(),
                'link' => 'mgplastic://shop/'.$u->id,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function itemsEvents(?int $limit): array
    {
        return Event::query()
            ->with(['translations', 'city'])
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (Event $e) => [
                'id' => $e->id,
                'title' => localized_name($e, 'title', 'فعالية'),
                'image_url' => PublicStorageUrl::try($e->image),
                'date' => $e->event_date
                    ? (\Illuminate\Support\Carbon::parse($e->event_date)->format('Y-m-d'))
                    : null,
                'location' => $e->city?->name ?? $e->address,
                'link' => 'mgplastic://event/'.$e->id,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function itemsStores(?int $limit): array
    {
        return User::query()
            ->whereIn('role', ['wholesale_distributor', 'retail_trader'])
            ->where('is_approved', true)
            ->where('is_active', true)
            ->with('city')
            ->orderByDesc('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'title' => $u->brand_name ?: $u->name,
                'subtitle' => $u->city?->name,
                'image_url' => $u->storeBrandImageUrl(),
                'phone' => $u->phone,
                'link' => 'mgplastic://shop/'.$u->id,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $highlights
     * @return list<array<string, mixed>>
     */
    protected function itemsMgBannerIcons(array $highlights): array
    {
        return array_map(function (array $h) {
            return [
                'key' => $h['key'],
                'title' => $h['label'] ?? $h['key'],
                'icon' => $h['icon'] ?? $h['key'],
                'link' => $h['link'] ?? $h['deep_link'] ?? null,
            ];
        }, $highlights);
    }

    /** @return list<array<string, mixed>> */
    protected function itemsPromoBanners(?int $limit): array
    {
        return PromoBanner::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(function (PromoBanner $b) {
                $link = match ($b->link_type) {
                    'url' => $b->link_url,
                    'product_collection' => $b->link_id ? 'mgplastic://collection/'.$b->link_id : null,
                    'product_promotion' => $b->link_id ? 'mgplastic://promotion/'.$b->link_id : null,
                    'product' => $b->link_id ? 'mgplastic://product/'.$b->link_id : null,
                    default => null,
                };

                return [
                    'id' => $b->id,
                    'title' => $b->title,
                    'subtitle' => $b->subtitle,
                    'image_url' => $b->imageUrl(),
                    'link' => $link,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function itemsTestimonials(?int $limit): array
    {
        return Testimonial::query()
            ->forPublic()
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(function (Testimonial $t) {
                $arr = $t->toPublicArray();

                return [
                    'id' => $arr['id'],
                    'name' => $arr['name'],
                    'role' => $arr['role'],
                    'city' => $arr['city'],
                    'rating' => $arr['rating'],
                    'body' => $arr['body'],
                    'product_hint' => $arr['product_hint'],
                    'avatar_initials' => $arr['avatar_initials'],
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function itemsPlumbers(?int $limit): array
    {
        return User::query()
            ->where('role', 'plumber')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->with('city')
            ->orderByDesc('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'title' => $u->name,
                'subtitle' => $u->city?->name,
                'image_url' => $u->profile_photo_url ?? null,
                'phone' => $u->phone,
                'link' => 'mgplastic://plumber/'.$u->id,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function mapHighlightsForApi(): array
    {
        $categories = ProductCategory::query()->with('translations')->orderBy('id')->get();
        $items = [];
        foreach ($this->highlightDefinitions() as $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }
            $categoryId = app(HomeHighlightsCategoryResolver::class)->resolve(
                $categories,
                $row['category_match'] ?? [],
                $row['category_exclude'] ?? [],
                $row['category_prefer'] ?? [],
            );
            $link = $row['link'] ?? $row['deep_link'] ?? null;
            if (! filled($link) && $categoryId) {
                $link = 'mgplastic://category/'.$categoryId;
            }
            $items[] = [
                'key' => (string) $row['key'],
                'label' => (string) ($row['label'] ?? $row['key']),
                'icon' => (string) ($row['icon'] ?? $row['key']),
                'link' => $link,
                'category_id' => $categoryId,
            ];
        }

        return $items;
    }

    /** @param  array<string, mixed>|null  $seeAll */
    protected function normalizeSeeAll(?array $seeAll): ?array
    {
        if (! is_array($seeAll) || empty($seeAll['link'])) {
            return null;
        }

        return [
            'text' => (string) ($seeAll['text'] ?? 'عرض المزيد'),
            'link' => (string) $seeAll['link'],
        ];
    }

    protected function seedProductTypes(): void
    {
        if (! Schema::hasTable('home_product_types') || HomeProductType::query()->exists()) {
            return;
        }
        foreach (config('product_types', []) as $i => $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }
            HomeProductType::query()->create([
                'key' => (string) $row['key'],
                'name' => (string) ($row['name'] ?? $row['key']),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'icon' => (string) ($row['icon'] ?? $row['key']),
                'image' => $row['image'] ?? null,
                'keywords' => $row['keywords'] ?? [],
                'exclude_keywords' => $row['exclude_keywords'] ?? [],
                'sort_order' => $i * 10,
                'is_active' => true,
            ]);
        }
    }

    protected function seedHighlights(): void
    {
        if (! Schema::hasTable('home_highlights') || HomeHighlight::query()->exists()) {
            return;
        }
        foreach (config('home_highlights', []) as $i => $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }
            HomeHighlight::query()->create([
                'key' => (string) $row['key'],
                'label' => (string) ($row['label'] ?? $row['key']),
                'icon' => (string) ($row['icon'] ?? $row['key']),
                'link' => $row['link'] ?? null,
                'deep_link' => $row['deep_link'] ?? null,
                'category_match' => $row['category_match'] ?? [],
                'category_prefer' => $row['category_prefer'] ?? [],
                'category_exclude' => $row['category_exclude'] ?? [],
                'sort_order' => $i * 10,
                'is_active' => true,
            ]);
        }
    }

    protected function seedSections(): void
    {
        if (! Schema::hasTable('home_layout_sections') || HomeLayoutSection::query()->exists()) {
            return;
        }

        $this->writeDefaultSections();
    }

    /**
     * Wipe and re-seed sections from config (sample-aligned defaults).
     */
    public function syncDefaultSections(bool $force = false): int
    {
        if (! Schema::hasTable('home_layout_sections')) {
            return 0;
        }

        if (! $force && HomeLayoutSection::query()->exists()) {
            return HomeLayoutSection::query()->count();
        }

        HomeLayoutSection::query()->delete();
        $this->writeDefaultSections();
        $this->bumpVersion();

        return HomeLayoutSection::query()->count();
    }

    protected function writeDefaultSections(): void
    {
        $defaults = config('home_layout.defaults', []);
        $titles = config('home_layout.default_titles', []);
        $limits = config('home_layout.default_limits', []);
        $seeAll = config('home_layout.default_see_all', []);
        $settings = config('home_layout.default_settings', []);
        $keyTypes = config('home_layout.key_types', []);

        $rolesByKey = [];
        $sortOrdersByKey = [];
        foreach ($defaults as $role => $keys) {
            foreach ($keys as $i => $key) {
                $rolesByKey[$key] = $rolesByKey[$key] ?? [];
                $rolesByKey[$key][] = $role;
                $sortOrdersByKey[$key][$role] = $i * 10;
            }
        }

        // Admin list order follows plumber sample, then any remaining keys.
        $adminOrder = $defaults['plumber'] ?? array_keys($rolesByKey);
        foreach (array_keys($rolesByKey) as $key) {
            if (! in_array($key, $adminOrder, true)) {
                $adminOrder[] = $key;
            }
        }

        foreach ($adminOrder as $sort => $key) {
            $type = $keyTypes[$key] ?? $key;
            $itemsOverride = match ($key) {
                'quick_actions' => config('home_layout.quick_actions_plumber', []),
                'shop_quick_links' => config('home_layout.shop_quick_links_trader', []),
                'shop_quick_links_distributor' => config('home_layout.shop_quick_links_distributor', []),
                default => null,
            };

            HomeLayoutSection::query()->create([
                'key' => $key,
                'type' => $type,
                'title' => $titles[$type] ?? null,
                'is_active' => true,
                'sort_order' => $sort * 10,
                'sort_orders' => $sortOrdersByKey[$key] ?? [],
                'roles' => array_values(array_unique($rolesByKey[$key] ?? [])),
                'limit' => $limits[$type] ?? null,
                'see_all' => $seeAll[$type] ?? null,
                'settings' => $settings[$type] ?? [],
                'items_override' => $itemsOverride,
            ]);
        }
    }
}

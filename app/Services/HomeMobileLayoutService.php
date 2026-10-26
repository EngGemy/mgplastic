<?php

namespace App\Services;

use App\Models\HomeHighlight;
use App\Models\HomeMobileSetting;
use App\Models\HomeProductType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class HomeMobileLayoutService
{
    public function ensureSeeded(): void
    {
        if (! Schema::hasTable('home_mobile_settings')) {
            return;
        }

        HomeMobileSetting::instance();

        if (Schema::hasTable('home_product_types') && HomeProductType::query()->doesntExist()) {
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

        if (Schema::hasTable('home_highlights') && HomeHighlight::query()->doesntExist()) {
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
    }

    public function forgetCache(): void
    {
        Cache::forget('home_mobile:product_type_defs');
        Cache::forget('home_mobile:highlight_defs');
        Cache::forget('home_mobile:layout');
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

    /** @return array<string, mixed> */
    public function layoutPayload(): array
    {
        $this->ensureSeeded();

        return Cache::remember('home_mobile:layout', 60, function () {
            $settings = HomeMobileSetting::instance();

            return array_merge($settings->toApiLayout(), [
                'product_types' => app(ProductTypeService::class)->catalogForHome(),
                'highlights_endpoint' => url('/api/v1/mobile/home/highlights'),
                'product_types_endpoints' => [
                    'trader' => url('/api/v1/mobile/trader/products/types'),
                    'distributor' => url('/api/v1/mobile/distributor/products/types'),
                ],
            ]);
        });
    }
}

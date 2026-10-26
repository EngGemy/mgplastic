<?php

namespace App\Services;

use App\Models\Product;
use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ProductTypeService
{
    /**
     * @return list<array{key: string, name: string, subtitle: string, icon: string, image: ?string, image_url: ?string, keywords: list<string>, exclude_keywords: list<string>}>
     */
    public function definitions(): array
    {
        if (app()->bound(HomeMobileLayoutService::class) || class_exists(HomeMobileLayoutService::class)) {
            try {
                return app(HomeMobileLayoutService::class)->productTypeDefinitions();
            } catch (\Throwable) {
                // fall through to config
            }
        }

        return $this->definitionsFromConfigOnly();
    }

    /**
     * @return list<array{key: string, name: string, subtitle: string, icon: string, image: ?string, image_url: ?string, keywords: list<string>, exclude_keywords: list<string>}>
     */
    public function definitionsFromConfigOnly(): array
    {
        $out = [];
        foreach (config('product_types', []) as $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }
            $image = isset($row['image']) ? ltrim((string) $row['image'], '/') : null;
            $out[] = [
                'key' => (string) $row['key'],
                'name' => (string) ($row['name'] ?? $row['key']),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'icon' => (string) ($row['icon'] ?? $row['key']),
                'image' => $image,
                'image_url' => $this->publicImageUrl($image),
                'keywords' => array_values(array_filter(array_map('strval', $row['keywords'] ?? []))),
                'exclude_keywords' => array_values(array_filter(array_map('strval', $row['exclude_keywords'] ?? []))),
            ];
        }

        return $out;
    }

    public function findDefinition(string $key): ?array
    {
        $key = strtolower(trim($key));
        foreach ($this->definitions() as $def) {
            if ($def['key'] === $key) {
                return $def;
            }
        }

        return null;
    }

    public function normalizeKey(?string $raw): ?string
    {
        $key = strtolower(trim((string) $raw));
        if ($key === '' || $this->findDefinition($key) === null) {
            return null;
        }

        return $key;
    }

    /**
     * @return list<array{key: string, name: string, subtitle: string, icon: string, image_url: ?string, products_count: int}>
     */
    public function catalogForHome(): array
    {
        $counts = [];
        foreach ($this->definitions() as $def) {
            $counts[$def['key']] = 0;
        }

        Product::query()
            ->with(['translations', 'tags'])
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$counts) {
                foreach ($products as $product) {
                    $type = $this->resolveForProduct($product);
                    if ($type && isset($counts[$type['key']])) {
                        $counts[$type['key']]++;
                    }
                }
            });

        return array_map(function (array $def) use ($counts) {
            return [
                'key' => $def['key'],
                'name' => $def['name'],
                'subtitle' => $def['subtitle'],
                'icon' => $def['icon'],
                'image_url' => $def['image_url'],
                'products_count' => (int) ($counts[$def['key']] ?? 0),
            ];
        }, $this->definitions());
    }

    /**
     * @return array{key: string, name: string}|null
     */
    public function resolveForProduct(Product $product): ?array
    {
        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            localized_name($product, 'name', ''),
            ...$product->relationLoaded('tags')
                ? $product->tags->pluck('name')->all()
                : [],
        ]))));

        if ($haystack === '') {
            return null;
        }

        foreach ($this->definitions() as $def) {
            if ($this->matches($haystack, $def)) {
                return ['key' => $def['key'], 'name' => $def['name']];
            }
        }

        return null;
    }

    public function applyFilter(Builder $query, string $key): Builder
    {
        $def = $this->findDefinition($key);
        if (! $def) {
            return $query->whereRaw('0 = 1');
        }

        $keywords = $def['keywords'];
        $exclude = $def['exclude_keywords'];

        return $query->where(function (Builder $outer) use ($keywords, $exclude) {
            $outer->where(function (Builder $q) use ($keywords) {
                foreach ($keywords as $i => $kw) {
                    $like = '%'.$kw.'%';
                    $method = $i === 0 ? 'where' : 'orWhere';
                    $q->{$method}(function (Builder $inner) use ($like) {
                        $inner->whereHas('translations', fn ($t) => $t->where('name', 'like', $like))
                            ->orWhereHas('tags', fn ($t) => $t->where('name', 'like', $like));
                    });
                }
            });

            foreach ($exclude as $kw) {
                $like = '%'.$kw.'%';
                $outer->whereDoesntHave('translations', fn ($t) => $t->where('name', 'like', $like))
                    ->whereDoesntHave('tags', fn ($t) => $t->where('name', 'like', $like));
            }
        });
    }

    protected function matches(string $haystack, array $def): bool
    {
        foreach ($def['exclude_keywords'] as $kw) {
            if ($kw !== '' && Str::contains($haystack, mb_strtolower($kw))) {
                return false;
            }
        }

        foreach ($def['keywords'] as $kw) {
            if ($kw !== '' && Str::contains($haystack, mb_strtolower($kw))) {
                return true;
            }
        }

        return false;
    }

    protected function publicImageUrl(?string $path): ?string
    {
        return PublicStorageUrl::try($path);
    }
}

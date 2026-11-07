<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductTag extends Model
{
    protected static function booted(): void
    {
        static::saving(function (ProductTag $tag): void {
            if (filled($tag->name)) {
                $tag->name = \App\Support\UnicodeText::nfkc((string) $tag->name);
            }
        });
    }

    protected $fillable = [
        'product_category_id',
        'name',
        'image',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_tag');
    }

    /** Absolute accordion icon URL (https), or null when missing. */
    public function resolvedImageUrl(): ?string
    {
        return self::absoluteHttpsUrl(PublicStorageUrl::try($this->image));
    }

    /**
     * Attach group_icon_url onto flat catalog / POS items.
     * Match by tag name + system category (product category, then parent) — never by tag name alone.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>|list<array<string, mixed>>  $items
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function decorateItemsWithGroupIconUrl(\Illuminate\Support\Collection|array $items): \Illuminate\Support\Collection
    {
        $items = collect($items);
        if ($items->isEmpty()) {
            return $items;
        }

        $names = [];
        $categoryIds = [];
        foreach ($items as $row) {
            $n = trim((string) ($row['tag'] ?? $row['group_name'] ?? ''));
            if ($n !== '') {
                $names[mb_strtolower($n)] = $n;
            }
            foreach ([(int) ($row['category_id'] ?? 0), (int) ($row['parent_category_id'] ?? 0)] as $cid) {
                if ($cid > 0) {
                    $categoryIds[$cid] = $cid;
                }
            }
        }

        if ($names === []) {
            return $items->map(static function (array $row) {
                $row['group_icon_url'] = $row['group_icon_url'] ?? null;

                return $row;
            })->values();
        }

        $itemCatIds = $items
            ->map(fn (array $r) => (int) ($r['category_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $parentByCat = $itemCatIds === []
            ? []
            : ProductCategory::query()
                ->whereIn('id', $itemCatIds)
                ->pluck('parent_id', 'id')
                ->map(fn ($id) => $id ? (int) $id : null)
                ->all();

        foreach ($parentByCat as $parentId) {
            if ($parentId) {
                $categoryIds[$parentId] = $parentId;
            }
        }

        /** @var array<string, string> $byCatName categoryId:lowerName => url */
        $byCatName = [];
        if ($categoryIds !== []) {
            $rows = static::query()
                ->whereIn('name', array_values($names))
                ->whereIn('product_category_id', array_values($categoryIds))
                ->whereNotNull('image')
                ->where('image', '!=', '')
                ->get(['id', 'product_category_id', 'name', 'image']);

            foreach ($rows as $tag) {
                $url = $tag->resolvedImageUrl();
                if (! $url) {
                    continue;
                }
                $byCatName[((int) $tag->product_category_id).':'.mb_strtolower((string) $tag->name)] = $url;
            }
        }

        return $items->map(static function (array $row) use ($byCatName, $parentByCat) {
            $tagName = trim((string) ($row['tag'] ?? $row['group_name'] ?? ''));
            $key = mb_strtolower($tagName);
            $catId = (int) ($row['category_id'] ?? 0);
            $parentId = (int) ($row['parent_category_id'] ?? ($parentByCat[$catId] ?? 0));

            $url = null;
            if ($key !== '') {
                if ($catId > 0 && isset($byCatName[$catId.':'.$key])) {
                    $url = $byCatName[$catId.':'.$key];
                } elseif ($parentId > 0 && isset($byCatName[$parentId.':'.$key])) {
                    $url = $byCatName[$parentId.':'.$key];
                }
            }

            $row['group_icon_url'] = $url;

            return $row;
        })->values();
    }

    /**
     * Attach image_url / icon_url / group_icon_url onto tag accordion groups (size sections).
     * Resolved by tag name + category (or preferredCategoryId / parent) — not tag name alone.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    public static function decorateGroupsWithIcons(array $groups, ?int $preferredCategoryId = null): array
    {
        if ($groups === []) {
            return $groups;
        }

        $names = [];
        $categoryIds = [];
        foreach ($groups as $g) {
            $n = trim((string) ($g['tag'] ?? $g['name'] ?? ''));
            if ($n !== '') {
                $names[mb_strtolower($n)] = $n;
            }
            $cid = (int) ($g['category_id'] ?? $preferredCategoryId ?? 0);
            if ($cid > 0) {
                $categoryIds[$cid] = $cid;
            }
        }

        if ($names === []) {
            return array_map(static function (array $g) {
                $g['image_url'] = $g['image_url'] ?? null;
                $g['icon_url'] = $g['icon_url'] ?? ($g['image_url'] ?? null);
                $g['group_icon_url'] = $g['group_icon_url'] ?? ($g['icon_url'] ?? null);

                return $g;
            }, $groups);
        }

        if ($preferredCategoryId) {
            $categoryIds[$preferredCategoryId] = $preferredCategoryId;
        }

        $parentByCat = $categoryIds === []
            ? []
            : ProductCategory::query()
                ->whereIn('id', array_values($categoryIds))
                ->pluck('parent_id', 'id')
                ->map(fn ($id) => $id ? (int) $id : null)
                ->all();

        foreach ($parentByCat as $parentId) {
            if ($parentId) {
                $categoryIds[$parentId] = $parentId;
            }
        }

        /** @var array<string, string> $byCatName categoryId:lowerName => url */
        $byCatName = [];
        $query = static::query()->whereIn('name', array_values($names));
        if ($categoryIds !== []) {
            $query->whereIn('product_category_id', array_values($categoryIds));
        }
        $rows = $query->get(['id', 'product_category_id', 'name', 'image']);

        foreach ($rows as $tag) {
            $url = $tag->resolvedImageUrl();
            if (! $url) {
                continue;
            }
            $byCatName[((int) $tag->product_category_id).':'.mb_strtolower((string) $tag->name)] = $url;
        }

        return array_map(static function (array $g) use ($byCatName, $preferredCategoryId, $parentByCat) {
            $tagName = trim((string) ($g['tag'] ?? $g['name'] ?? ''));
            $key = mb_strtolower($tagName);
            $catId = (int) ($g['category_id'] ?? $preferredCategoryId ?? 0);
            $parentId = (int) ($parentByCat[$catId] ?? 0);

            $url = null;
            if ($key !== '') {
                if ($catId > 0 && isset($byCatName[$catId.':'.$key])) {
                    $url = $byCatName[$catId.':'.$key];
                } elseif ($parentId > 0 && isset($byCatName[$parentId.':'.$key])) {
                    $url = $byCatName[$parentId.':'.$key];
                } elseif ($preferredCategoryId && isset($byCatName[$preferredCategoryId.':'.$key])) {
                    $url = $byCatName[$preferredCategoryId.':'.$key];
                }
            }

            $g['image_url'] = $url;
            $g['icon_url'] = $url;
            $g['group_icon_url'] = $url;

            return $g;
        }, $groups);
    }

    /** Force absolute https URLs for mobile clients. */
    public static function absoluteHttpsUrl(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }

        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (str_starts_with($url, 'http://')) {
            return 'https://'.substr($url, strlen('http://'));
        }
        if (str_starts_with($url, 'https://')) {
            return $url;
        }

        $absolute = url($url);
        if (str_starts_with($absolute, 'http://')) {
            return 'https://'.substr($absolute, strlen('http://'));
        }

        return $absolute;
    }

    /**
     * @param  list<mixed>  $names
     * @return list<string>
     */
    public static function normalizeNames(array $names): array
    {
        $clean = [];

        foreach ($names as $name) {
            foreach (self::splitList((string) $name) as $part) {
                $part = trim($part);
                if ($part === '' || $part === '-' || $part === '—') {
                    continue;
                }
                $part = mb_substr($part, 0, 80);
                $clean[mb_strtolower($part)] = $part;
            }
        }

        return array_values($clean);
    }

    /**
     * @return list<string>
     */
    public static function splitList(string $raw): array
    {
        $parts = preg_split('/\s*[,،]\s*/u', trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    /**
     * @param  list<string>  $names
     */
    public static function syncNamesOntoProduct(Product $product, array $names): void
    {
        $names = self::normalizeNames($names);
        $categoryId = $product->product_category_id;
        if (! $categoryId || $names === []) {
            $product->tags()->sync([]);

            return;
        }

        $product->loadMissing('category');
        $scope = array_values(array_unique(array_filter([
            $categoryId,
            $product->category?->parent_id,
        ])));

        $existing = self::query()
            ->whereIn('product_category_id', $scope)
            ->get()
            ->keyBy(fn (self $tag) => mb_strtolower($tag->name));

        $ids = [];
        foreach ($names as $name) {
            $key = mb_strtolower($name);
            $tag = $existing->get($key);

            if (! $tag) {
                $tag = self::query()->create([
                    'product_category_id' => $categoryId,
                    'name' => $name,
                ]);
                $existing->put($key, $tag);
            }

            $ids[] = $tag->id;
        }

        $product->tags()->sync(array_values(array_unique($ids)));
    }

    /**
     * @return list<string>
     */
    public static function suggestionsFor(?int $parentCategoryId, ?int $categoryId): array
    {
        $ids = array_values(array_unique(array_filter([$parentCategoryId, $categoryId])));

        if ($categoryId) {
            $storedParentId = ProductCategory::query()->whereKey($categoryId)->value('parent_id');
            if ($storedParentId) {
                $ids[] = (int) $storedParentId;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        $childIds = ProductCategory::query()->whereIn('parent_id', $ids)->pluck('id')->all();
        $ids = array_values(array_unique([...$ids, ...$childIds]));

        return self::query()
            ->whereIn('product_category_id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->unique(fn (string $name) => mb_strtolower($name))
            ->values()
            ->all();
    }
}

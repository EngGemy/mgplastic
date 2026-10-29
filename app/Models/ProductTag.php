<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductTag extends Model
{
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

    /** Absolute accordion icon URL, or null when missing. */
    public function resolvedImageUrl(): ?string
    {
        return PublicStorageUrl::try($this->image);
    }

    /**
     * Attach image_url / icon_url onto tag accordion groups (size sections).
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
        foreach ($groups as $g) {
            $n = trim((string) ($g['tag'] ?? $g['name'] ?? ''));
            if ($n !== '') {
                $names[mb_strtolower($n)] = $n;
            }
        }

        if ($names === []) {
            return array_map(static function (array $g) {
                $g['image_url'] = $g['image_url'] ?? null;
                $g['icon_url'] = $g['icon_url'] ?? ($g['image_url'] ?? null);

                return $g;
            }, $groups);
        }

        $rows = static::query()
            ->whereIn('name', array_values($names))
            ->get(['id', 'product_category_id', 'name', 'image']);

        /** @var array<string, string> $byCatName categoryId:lowerName => url */
        $byCatName = [];
        /** @var array<string, string> $byName lowerName => url (first with image wins) */
        $byName = [];

        foreach ($rows as $tag) {
            $url = $tag->resolvedImageUrl();
            if (! $url) {
                continue;
            }
            $key = mb_strtolower((string) $tag->name);
            $byCatName[((int) $tag->product_category_id).':'.$key] = $url;
            $byName[$key] ??= $url;
        }

        return array_map(static function (array $g) use ($byCatName, $byName, $preferredCategoryId) {
            $tagName = trim((string) ($g['tag'] ?? $g['name'] ?? ''));
            $key = mb_strtolower($tagName);
            $catId = (int) ($g['category_id'] ?? $preferredCategoryId ?? 0);

            $url = null;
            if ($catId > 0 && isset($byCatName[$catId.':'.$key])) {
                $url = $byCatName[$catId.':'.$key];
            } elseif ($key !== '' && isset($byName[$key])) {
                $url = $byName[$key];
            } elseif (! empty($g['image_url'])) {
                $url = (string) $g['image_url'];
            } elseif (! empty($g['items'][0]['image'])) {
                $url = PublicStorageUrl::try((string) $g['items'][0]['image']);
            }

            $g['image_url'] = $url;
            $g['icon_url'] = $url;

            return $g;
        }, $groups);
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

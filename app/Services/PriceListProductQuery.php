<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Collection;

class PriceListProductQuery
{
    /**
     * @param  list<int>  $exceptIds
     * @return Collection<int, Product>
     */
    public function matching(?int $parentId, ?int $categoryId, ?string $tag, ?string $search, array $exceptIds = [], int $limit = 250): Collection
    {
        $query = Product::query()->with(['translations', 'category.translations']);

        $exceptIds = array_values(array_filter($exceptIds));
        if ($exceptIds !== []) {
            $query->whereNotIn('id', $exceptIds);
        }

        if ($categoryId) {
            $query->where('product_category_id', $categoryId);
        } elseif ($parentId) {
            $ids = ProductCategory::query()
                ->where('id', $parentId)
                ->orWhere('parent_id', $parentId)
                ->pluck('id');
            $query->whereIn('product_category_id', $ids);
        }

        $tag = trim((string) $tag);
        if ($tag !== '') {
            $query->whereHas('tags', fn ($tags) => $tags->where('name', $tag));
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query->search($search);
        }

        return $query->orderBy('id')->limit($limit)->get();
    }
}

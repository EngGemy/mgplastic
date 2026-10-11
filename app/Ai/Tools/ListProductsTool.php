<?php

namespace App\Ai\Tools;

use App\Models\Product;
use App\Models\ProductCategory;

class ListProductsTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_products';
    }

    public function description(): string
    {
        return 'List products with id, translated name, category, classification, points, and created_at. Default order: newest first (created_at DESC). Use to answer “آخر منتج” or product names.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'q' => ['type' => 'string', 'description' => 'Search name/description/classification/id'],
            'category_id' => ['type' => 'integer', 'description' => 'Filter by product_category_id'],
            'limit' => ['type' => 'integer', 'description' => 'Default 20, max 100'],
            'order' => [
                'type' => 'string',
                'enum' => ['newest', 'oldest', 'id_desc', 'id_asc'],
                'description' => 'Default newest (created_at DESC)',
            ],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 20)));
        $q = trim((string) ($arguments['q'] ?? ''));
        $order = (string) ($arguments['order'] ?? 'newest');

        $query = Product::query()
            ->with([
                'translations',
                'category.translations',
                'category.parent.translations',
            ]);

        if ($q !== '') {
            $query->search($q);
        }

        if (! empty($arguments['category_id'])) {
            $query->where('product_category_id', (int) $arguments['category_id']);
        }

        $totalMatching = (clone $query)->count();

        $ordered = clone $query;
        match ($order) {
            'oldest' => $ordered->orderBy('created_at')->orderBy('id'),
            'id_asc' => $ordered->orderBy('id'),
            'id_desc' => $ordered->orderByDesc('id'),
            default => $ordered->orderByDesc('created_at')->orderByDesc('id'),
        };

        $items = $ordered->limit($limit)->get();

        return [
            'total_matching' => $totalMatching,
            'returned' => $items->count(),
            'order' => $order,
            'latest_product' => $items->first() ? $this->mapProduct($items->first()) : null,
            'products' => $items->map(fn (Product $p) => $this->mapProduct($p))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function mapProduct(Product $product): array
    {
        $nameAr = optional($product->translate('ar'))->name;
        $nameEn = optional($product->translate('en'))->name;
        $name = $nameAr ?: $nameEn ?: ('Product #'.$product->id);

        $category = $product->category;
        $categoryName = $category ? $this->categoryName($category) : null;
        $parent = $category?->parent;
        $parentName = $parent ? $this->categoryName($parent) : null;

        return [
            'id' => $product->id,
            'name' => $name,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'category_id' => $product->product_category_id,
            'category_name' => $categoryName,
            'parent_category_id' => $parent?->id,
            'parent_category_name' => $parentName,
            'classification' => $product->classification,
            'points_per_unit' => $product->points_per_unit,
            'created_at' => optional($product->created_at)?->toIso8601String(),
            'updated_at' => optional($product->updated_at)?->toIso8601String(),
        ];
    }

    private function categoryName(ProductCategory $cat): ?string
    {
        try {
            return optional($cat->translate('ar'))->name
                ?? optional($cat->translate('en'))->name
                ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}

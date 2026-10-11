<?php

namespace App\Ai\Tools;

use App\Models\Product;
use App\Models\ProductCategory;

class ProductCatalogSummaryTool extends AbstractTool
{
    public function name(): string
    {
        return 'product_catalog_summary';
    }

    public function description(): string
    {
        return 'Summarize product catalog counts by category.';
    }

    public function inputSchema(): array
    {
        return $this->schema();
    }

    public function handle(array $arguments): array
    {
        $total = Product::query()->count();
        $categories = ProductCategory::query()->withCount('products')->orderBy('id')->get();

        return [
            'total_products' => $total,
            'categories' => $categories->map(function (ProductCategory $cat) {
                $name = null;
                try {
                    $name = optional($cat->translate('ar'))->name
                        ?? optional($cat->translate('en'))->name
                        ?? $cat->name
                        ?? null;
                } catch (\Throwable) {
                    $name = $cat->name ?? null;
                }

                return [
                    'id' => $cat->id,
                    'name' => $name,
                    'products_count' => (int) ($cat->products_count ?? 0),
                ];
            })->all(),
        ];
    }
}

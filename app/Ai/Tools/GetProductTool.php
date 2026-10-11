<?php

namespace App\Ai\Tools;

use App\Models\Product;

class GetProductTool extends AbstractTool
{
    public function name(): string
    {
        return 'get_product';
    }

    public function description(): string
    {
        return 'Get one product by id with translated name, category path, points, dimensions, and timestamps.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'product_id' => ['type' => 'integer', 'description' => 'Product id'],
        ], ['product_id']);
    }

    public function handle(array $arguments): array
    {
        $id = (int) ($arguments['product_id'] ?? 0);
        $product = Product::query()
            ->with([
                'translations',
                'category.translations',
                'category.parent.translations',
                'images',
            ])
            ->find($id);

        if (! $product) {
            return ['found' => false, 'product_id' => $id];
        }

        $nameAr = optional($product->translate('ar'))->name;
        $nameEn = optional($product->translate('en'))->name;

        $cat = $product->category;
        $parent = $cat?->parent;

        return [
            'found' => true,
            'product' => [
                'id' => $product->id,
                'name' => $nameAr ?: $nameEn ?: ('Product #'.$product->id),
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'description_ar' => optional($product->translate('ar'))->description,
                'description_en' => optional($product->translate('en'))->description,
                'category_id' => $product->product_category_id,
                'category_name' => optional($cat?->translate('ar'))->name
                    ?? optional($cat?->translate('en'))->name,
                'parent_category_id' => $parent?->id,
                'parent_category_name' => optional($parent?->translate('ar'))->name
                    ?? optional($parent?->translate('en'))->name,
                'classification' => $product->classification,
                'points_per_unit' => $product->points_per_unit,
                'length_m' => $product->length_m,
                'thickness_mm' => $product->thickness_mm,
                'volume_ml' => $product->volume_ml,
                'main_image' => $product->main_image,
                'images_count' => $product->images->count(),
                'created_at' => optional($product->created_at)?->toIso8601String(),
                'updated_at' => optional($product->updated_at)?->toIso8601String(),
            ],
        ];
    }
}

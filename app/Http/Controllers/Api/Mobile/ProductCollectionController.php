<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCollectionController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $kind = $request->query('kind');

        $items = ProductCollection::query()
            ->where('is_active', true)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ProductCollection $c) => $this->mapCollection($c, includeProducts: false))
            ->values();

        return $this->success(['items' => $items], 'مجموعات المنتجات');
    }

    public function show(Request $request, ProductCollection $collection): JsonResponse
    {
        if (! $collection->is_active) {
            return $this->error('المجموعة غير متاحة', 404);
        }

        $collection->load(['products.translations', 'products.images', 'products.category.translations']);

        return $this->success(
            $this->mapCollection($collection, includeProducts: true),
            'تفاصيل المجموعة',
        );
    }

    /** @return array<string, mixed> */
    protected function mapCollection(ProductCollection $c, bool $includeProducts): array
    {
        $row = [
            'id' => $c->id,
            'slug' => $c->slug,
            'title' => $c->title,
            'subtitle' => $c->subtitle,
            'description' => $c->description,
            'kind' => $c->kind,
            'kind_label' => $c->kindLabel(),
            'cover_image_url' => $c->coverImageUrl(),
            'products_count' => (int) ($c->products_count ?? $c->products()->count()),
            'sort_order' => (int) $c->sort_order,
        ];

        if ($includeProducts) {
            $row['products'] = $c->products->map(function (Product $product) {
                $piecesPerBox = (int) ($product->pieces_per_box ?? 0);
                $image = $product->catalog_image_path
                    ?? $product->main_image
                    ?? $product->images->sortBy('sort')->first()?->image;

                return [
                    'id' => (int) $product->id,
                    'name' => localized_name($product, 'name', 'منتج #'.$product->id),
                    'image' => $image,
                    'image_url' => $product->display_image_url
                        ?? ($image ? asset('storage/'.ltrim($image, '/')) : null),
                    'points_per_unit' => null,
                    'has_points' => ((float) ($product->points_per_unit ?? 0)) > 0,
                    'pieces_per_box' => $piecesPerBox > 0 ? $piecesPerBox : null,
                    'order_unit' => $piecesPerBox > 0 ? 'box' : 'piece',
                    'category_id' => $product->product_category_id ? (int) $product->product_category_id : null,
                ];
            })->values();
        }

        return $row;
    }
}

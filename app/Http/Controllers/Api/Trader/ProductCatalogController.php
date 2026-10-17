<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paginated product catalog for retail traders when placing a supply order
 * to their wholesale distributor (POST /mobile/trader/orders).
 */
class ProductCatalogController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $lang = $this->language($request);
        app()->setLocale($lang);

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $page = $this->catalogQuery($request)->latest('id')->paginate($perPage);

        $items = collect($page->items())->map(fn (Product $product) => $this->mapCatalogProduct($product))->values();

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ], 'كتالوج منتجات MG — للمصنع والمخزن');
    }

    /**
     * Categories for MG catalog filters (wholesale + retail mobile).
     */
    public function categories(Request $request): JsonResponse
    {
        $lang = $this->language($request);
        app()->setLocale($lang);

        $categories = ProductCategory::query()
            ->with('translations')
            ->withCount('products')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductCategory $c) => [
                'id' => (int) $c->id,
                'name' => localized_name($c, 'name', 'تصنيف #'.$c->id),
                'slug' => $c->slug,
                'image' => $c->image,
                'image_url' => $c->image ? asset('storage/'.ltrim($c->image, '/')) : null,
                'products_count' => (int) $c->products_count,
                'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
            ])
            ->values();

        return $this->success(['items' => $categories], 'تصنيفات منتجات MG');
    }

    protected function catalogQuery(Request $request): Builder
    {
        $query = Product::query()
            ->with(['translations', 'images', 'category.translations']);

        if ($request->filled('category_id')) {
            $query->where('product_category_id', (int) $request->query('category_id'));
        }

        if ($request->filled('q') || $request->filled('search')) {
            $query->search((string) ($request->query('q') ?? $request->query('search')));
        }

        $tags = $this->requestedTags($request);
        if ($tags !== []) {
            $query->whereHas('tags', fn ($tagsQuery) => $tagsQuery->whereIn('name', $tags));
        }

        // Optional: ?has_points=1|0 — default returns ALL MG products (with or without points)
        if ($request->filled('has_points')) {
            if ($request->boolean('has_points')) {
                $query->where('points_per_unit', '>', 0);
            } else {
                $query->where(function ($q) {
                    $q->whereNull('points_per_unit')->orWhere('points_per_unit', '<=', 0);
                });
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapCatalogProduct(Product $product, ?int $availableQuantity = null): array
    {
        $gallery = $product->images->sortBy('sort')->first()?->image;
        $image = $product->catalog_image_path
            ?? $product->main_image
            ?? $gallery
            ?? null;
        $points = (float) ($product->points_per_unit ?? 0);

        // Network apps see totals on orders only — not the per-unit rate (decimal UX).
        // mg_no_points = MG catalog without loyalty points (still orderable).
        $item = [
            'id' => (int) $product->id,
            'name' => localized_name($product, 'name', 'منتج #'.$product->id),
            'description' => api_plain_string(localized_name($product, 'description', '')),
            'image' => $image,
            'image_url' => $product->display_image_url
                ?? ($image ? asset('storage/'.ltrim($image, '/')) : null),
            'points_per_unit' => null,
            'has_points' => $points > 0,
            'product_kind' => $product->productKind(),
            'is_mg' => true,
            'awards_points' => $product->awardsPoints(),
            'category_id' => $product->product_category_id ? (int) $product->product_category_id : null,
            'category_name' => $product->category
                ? localized_name($product->category, 'name', '')
                : null,
        ];

        if ($availableQuantity !== null) {
            $item['available_quantity'] = $availableQuantity;
        }

        return $item;
    }

    /**
     * @return list<string>
     */
    protected function requestedTags(Request $request): array
    {
        $raw = $request->query('tags', $request->query('tag'));
        if ($raw === null || $raw === '') {
            return [];
        }

        return ProductTag::normalizeNames(is_array($raw) ? $raw : [$raw]);
    }

    protected function language(Request $request): string
    {
        $raw = (string) $request->header('Accept-Language', 'ar');
        $lang = substr(strtolower(str_replace('_', '-', $raw)), 0, 2);

        return in_array($lang, ['ar', 'en'], true) ? $lang : 'ar';
    }
}

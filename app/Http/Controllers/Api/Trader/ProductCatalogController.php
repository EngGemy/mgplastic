<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Product;
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

        $query = Product::query()
            ->with(['translations', 'images', 'category.translations']);

        if ($request->filled('category_id')) {
            $query->where('product_category_id', (int) $request->query('category_id'));
        }

        if ($request->filled('q') || $request->filled('search')) {
            $term = trim((string) ($request->query('q') ?? $request->query('search')));
            if ($term !== '') {
                $query->whereTranslationLike('name', '%'.$term.'%');
            }
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

        $page = $query->latest('id')->paginate($perPage);

        $items = collect($page->items())->map(function (Product $product) {
            $gallery = $product->images->sortBy('sort')->first()?->image;
            $image = $product->catalog_image_path
                ?? $product->main_image
                ?? $gallery
                ?? null;
            $points = (float) ($product->points_per_unit ?? 0);

            return [
                'id' => (int) $product->id,
                'name' => localized_name($product, 'name', 'منتج #'.$product->id),
                'description' => localized_name($product, 'description', ''),
                'image' => $image,
                'image_url' => $product->display_image_url
                    ?? ($image ? asset('storage/'.ltrim($image, '/')) : null),
                'points_per_unit' => $points,
                'has_points' => $points > 0,
                'category_id' => $product->product_category_id ? (int) $product->product_category_id : null,
                'category_name' => $product->category
                    ? localized_name($product->category, 'name', '')
                    : null,
            ];
        })->values();

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ], 'كتالوج منتجات MG');
    }

    private function language(Request $request): string
    {
        $raw = (string) $request->header('Accept-Language', 'ar');
        $lang = substr(strtolower(str_replace('_', '-', $raw)), 0, 2);

        return in_array($lang, ['ar', 'en'], true) ? $lang : 'ar';
    }
}

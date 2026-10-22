<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use App\Services\ProductTypeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        $orderUnit = $this->requestedOrderUnit($request);
        $baseQuery = $this->catalogQuery($request, applyOrderUnit: false);

        $boxCount = (clone $baseQuery)->where('pieces_per_box', '>', 0)->count();
        $pieceCount = (clone $baseQuery)->where(function ($q) {
            $q->whereNull('pieces_per_box')->orWhere('pieces_per_box', '<=', 0);
        })->count();

        $query = $this->catalogQuery($request, applyOrderUnit: true)->latest('id');
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);
        $page = $query->paginate($perPage);

        $items = collect($page->items())->map(fn (Product $product) => $this->mapCatalogProduct($product))->values();

        return $this->success([
            'items' => $items,
            'units' => [
                'all' => $boxCount + $pieceCount,
                'box' => $boxCount,
                'piece' => $pieceCount,
            ],
            'filters' => [
                'order_unit' => $orderUnit,
                'product_family' => $this->requestedProductFamily($request),
                'product_type' => $this->requestedPieceType($request),
                'category_id' => $request->filled('category_id') ? (int) $request->query('category_id') : null,
                'search' => $request->query('q') ?? $request->query('search'),
            ],
            'pagination' => $this->paginationMeta($page),
        ], 'كتالوج منتجات MG — صناديق / قطع (بدون نقاط للمنتج)');
    }

    /**
     * Piece types for wholesale home (مواسير مياه · أكواع · …).
     * GET /mobile/{trader|distributor}/products/types
     */
    public function types(): JsonResponse
    {
        return $this->success(
            app(ProductTypeService::class)->catalogForHome(),
            'أقسام رئيسية حسب نوع القطعة'
        );
    }

    /**
     * Categories for MG catalog filters (wholesale + retail mobile).
     */
    public function categories(Request $request): JsonResponse
    {
        $lang = $this->language($request);
        app()->setLocale($lang);

        $asSystems = $request->boolean('systems')
            || in_array(strtolower((string) $request->query('view')), ['systems', 'tree'], true);

        $categories = ProductCategory::query()
            ->with(['translations', 'children.translations', 'children' => fn ($q) => $q->withCount('products')])
            ->withCount('products')
            ->orderBy('id')
            ->get();

        if ($asSystems) {
            $systems = $categories
                ->whereNull('parent_id')
                ->values()
                ->map(fn (ProductCategory $c) => $this->mapCategory($c, includeChildren: true));

            return $this->success(['items' => $systems], 'أنظمة / تصنيفات رئيسية للمنتجات');
        }

        $items = $categories->map(fn (ProductCategory $c) => $this->mapCategory($c, includeChildren: false))->values();

        return $this->success(['items' => $items], 'تصنيفات منتجات MG');
    }

    protected function catalogQuery(Request $request, bool $applyOrderUnit = true): Builder
    {
        $query = Product::query()
            ->with(['translations', 'images', 'tags', 'category.translations', 'category.parent.translations']);

        if ($request->filled('category_id')) {
            $catId = (int) $request->query('category_id');
            $ids = ProductCategory::query()
                ->whereKey($catId)
                ->orWhere('parent_id', $catId)
                ->pluck('id')
                ->all();
            $query->whereIn('product_category_id', $ids === [] ? [$catId] : $ids);
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

        if ($applyOrderUnit) {
            $orderUnit = $this->requestedOrderUnit($request);
            if ($orderUnit === 'box') {
                $query->where('pieces_per_box', '>', 0);
            } elseif ($orderUnit === 'piece') {
                $query->where(function ($q) {
                    $q->whereNull('pieces_per_box')->orWhere('pieces_per_box', '<=', 0);
                });
            }
        }

        // POS filter: pipes vs accessories (ملحقات)
        $family = $this->requestedProductFamily($request);
        if ($family !== null) {
            $accessoryIds = ProductCategory::query()
                ->with('parent')
                ->get()
                ->filter(fn (ProductCategory $c) => $c->isAccessoriesFamily())
                ->pluck('id')
                ->all();

            if ($family === 'accessory') {
                $query->whereIn('product_category_id', $accessoryIds === [] ? [0] : $accessoryIds);
            } else {
                $query->where(function ($q) use ($accessoryIds) {
                    $q->whereNull('product_category_id');
                    if ($accessoryIds !== []) {
                        $q->orWhereNotIn('product_category_id', $accessoryIds);
                    }
                });
            }
        }

        // Home piece-type filter: ?product_type=elbow|tee|cap|…
        $pieceType = $this->requestedPieceType($request);
        if ($pieceType !== null) {
            app(ProductTypeService::class)->applyFilter($query, $pieceType);
        }

        return $query;
    }

    /** @return 'pipe'|'accessory'|null */
    protected function requestedProductFamily(Request $request): ?string
    {
        $raw = strtolower(trim((string) (
            $request->query('product_family')
            ?? $request->query('family')
            ?? ''
        )));

        // Legacy: product_type=pipe|accessory still means family (before piece-types existed).
        if ($raw === '') {
            $legacy = strtolower(trim((string) $request->query('product_type', '')));
            if (in_array($legacy, ['pipe', 'pipes', 'accessory', 'accessories', 'مواسير', 'ملحقات', 'fitting', 'fittings'], true)) {
                $raw = $legacy;
            }
        }

        return match ($raw) {
            'pipe', 'pipes', 'مواسير' => 'pipe',
            'accessory', 'accessories', 'ملحقات', 'fitting', 'fittings' => 'accessory',
            default => null,
        };
    }

    /** Piece type key from home sections (elbow, tee, …) — not pipe/accessory family. */
    protected function requestedPieceType(Request $request): ?string
    {
        $raw = strtolower(trim((string) $request->query('product_type', '')));
        if (in_array($raw, ['pipe', 'pipes', 'accessory', 'accessories', 'مواسير', 'ملحقات', 'fitting', 'fittings', ''], true)) {
            return null;
        }

        return app(ProductTypeService::class)->normalizeKey($raw);
    }

    /** @return 'box'|'piece'|null */
    protected function requestedOrderUnit(Request $request): ?string
    {
        $raw = strtolower(trim((string) ($request->query('order_unit') ?? $request->query('unit') ?? '')));

        return in_array($raw, ['box', 'piece'], true) ? $raw : null;
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<\App\Models\Product>  $page
     * @return array<string, mixed>
     */
    protected function paginationMeta($page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'has_more' => $page->hasMorePages(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'prev_page' => $page->currentPage() > 1 ? $page->currentPage() - 1 : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapCatalogProductPublic(Product $product, ?int $availableQuantity = null): array
    {
        return $this->mapCatalogProduct($product, $availableQuantity);
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
        $piecesPerBox = (int) ($product->pieces_per_box ?? 0);
        $pieceType = app(ProductTypeService::class)->resolveForProduct($product);

        $item = [
            'id' => (int) $product->id,
            'name' => localized_name($product, 'name', 'منتج #'.$product->id),
            'description' => api_plain_string(localized_name($product, 'description', '')),
            'image' => $image,
            'image_url' => $product->display_image_url
                ?? ($image ? $this->storageUrlIfExists($image) : null),
            'points_per_unit' => null,
            'has_points' => $points > 0,
            'product_kind' => $product->productKind(),
            'is_mg' => true,
            'awards_points' => $product->awardsPoints(),
            'pieces_per_box' => $piecesPerBox > 0 ? $piecesPerBox : null,
            'order_unit' => $piecesPerBox > 0 ? 'box' : 'piece',
            'is_accessory' => $product->isAccessories(),
            'product_family' => $product->isAccessories() ? 'accessory' : 'pipe',
            'product_type' => $pieceType['key'] ?? null,
            'product_type_name' => $pieceType['name'] ?? null,
            'category_id' => $product->product_category_id ? (int) $product->product_category_id : null,
            'category_name' => $product->category
                ? localized_name($product->category, 'name', '')
                : null,
            'parent_category_id' => $product->category?->parent_id
                ? (int) $product->category->parent_id
                : null,
            'parent_category_name' => $product->category?->parent
                ? localized_name($product->category->parent, 'name', '')
                : null,
        ];

        if ($availableQuantity !== null) {
            $item['available_quantity'] = $availableQuantity;
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapCategory(ProductCategory $c, bool $includeChildren): array
    {
        $name = $this->normalizedCategoryName(localized_name($c, 'name', 'تصنيف #'.$c->id));
        $short = $this->categoryShortDescription($c);
        $imageUrl = $this->storageUrlIfExists($c->image);

        $payload = [
            'id' => (int) $c->id,
            'name' => $name,
            'slug' => $c->slug,
            'image' => $imageUrl ? $c->image : null,
            'image_url' => $imageUrl,
            'short_description' => $short,
            'description' => $short,
            'products_count' => (int) $c->products_count + ($includeChildren
                ? (int) $c->children->sum('products_count')
                : 0),
            'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
            'is_accessories' => $c->isAccessoriesFamily(),
        ];

        if ($includeChildren) {
            $payload['children'] = $c->children->map(fn (ProductCategory $child) => [
                'id' => (int) $child->id,
                'name' => $this->normalizedCategoryName(localized_name($child, 'name', 'تصنيف #'.$child->id)),
                'slug' => $child->slug,
                'image' => $this->storageUrlIfExists($child->image) ? $child->image : null,
                'image_url' => $this->storageUrlIfExists($child->image),
                'short_description' => $this->categoryShortDescription($child),
                'products_count' => (int) $child->products_count,
                'parent_id' => (int) $c->id,
                'is_accessories' => $child->isAccessoriesFamily(),
            ])->values();
        }

        return $payload;
    }

    protected function categoryShortDescription(ProductCategory $c): ?string
    {
        $raw = trim((string) (
            optional($c->translate('ar'))->description
            ?? optional($c->translate(app()->getLocale()))->description
            ?? ''
        ));

        if ($raw === '') {
            $raw = $this->defaultSystemBlurb($c);
        }

        if ($raw === '') {
            return null;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '');

        return $plain !== '' ? Str::limit($plain, 120, '…') : null;
    }

    protected function defaultSystemBlurb(ProductCategory $c): string
    {
        $blob = mb_strtolower(trim(implode(' ', array_filter([
            (string) $c->slug,
            localized_name($c, 'name', ''),
        ]))));

        return match (true) {
            Str::contains($blob, ['اوروب', 'أوروب', 'europ']) => 'مقاسات ومواصفات · EN Standard',
            Str::contains($blob, ['امريك', 'أمريك', 'american', 'astm', 'schedule']) => 'Schedule / ASTM',
            Str::contains($blob, ['ذهب', 'gold']) => 'مواسير النظام الذهبي',
            Str::contains($blob, ['برتق', 'orange']) => 'نظام البرتقالي للصرف والتمديدات',
            Str::contains($blob, ['رماد', 'grey', 'gray']) => 'النظام الرمادي',
            Str::contains($blob, ['ابار', 'آبار', 'well']) => 'مواسير الآبار والطلمبات',
            default => '',
        };
    }

    protected function normalizedCategoryName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? $name;
        $name = str_replace(['الاوروربي', 'الأوروربي'], 'الاوروبي', $name);

        return trim($name);
    }

    protected function storageUrlIfExists(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
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

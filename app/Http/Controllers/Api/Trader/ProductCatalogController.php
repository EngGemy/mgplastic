<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use App\Services\NetworkInventoryService;
use App\Services\ProductTypeService;
use App\Support\ProductSizeSorter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        $payload = $this->buildCatalogPayload($request);

        return $this->success($payload, 'كتالوج منتجات MG — تصنيف ثم تاج للملحقات');
    }

    /**
     * Shared catalog payload for trader / distributor / plumber.
     *
     * Accessories (or ?grouped=1 / view=grouped): full Category → Tag → products tree.
     * Pipes: paginated flat list + groups for the current page.
     *
     * @param  callable(Product): array<string, mixed>|null  $mapProduct
     * @return array<string, mixed>
     */
    protected function buildCatalogPayload(Request $request, ?callable $mapProduct = null): array
    {
        $mapProduct ??= fn (Product $product) => $this->mapCatalogProduct($product);

        $orderUnit = $this->requestedOrderUnit($request);
        $family = $this->requestedProductFamily($request);
        $groupedView = $this->wantsGroupedBrowse($request, $family);
        $sizeOrder = ProductSizeSorter::normalizeOrder(
            $request->query('size_order', $request->query('sort'))
        );

        $baseQuery = $this->catalogQuery($request, applyOrderUnit: false);
        $boxCount = (clone $baseQuery)->where('pieces_per_box', '>', 0)->count();
        $pieceCount = (clone $baseQuery)->where(function ($q) {
            $q->whereNull('pieces_per_box')->orWhere('pieces_per_box', '<=', 0);
        })->count();

        $filters = [
            'order_unit' => $orderUnit,
            'product_family' => $family,
            'product_type' => $this->requestedPieceType($request),
            'category_id' => $request->filled('category_id') ? (int) $request->query('category_id') : null,
            'search' => $request->query('q') ?? $request->query('search'),
            'grouped' => $groupedView,
            'size_order' => $sizeOrder,
            'tags' => $this->requestedTags($request),
        ];

        $units = [
            'all' => $boxCount + $pieceCount,
            'box' => $boxCount,
            'piece' => $pieceCount,
        ];

        $inventory = app(NetworkInventoryService::class);

        if ($groupedView) {
            $limit = min(max((int) $request->integer('per_page', 500), 1), 500);
            $products = $this->catalogQuery($request, applyOrderUnit: true)
                ->limit($limit)
                ->get();

            $items = $products
                ->map(fn (Product $product) => $mapProduct($product))
                ->values();
            $items = $this->sortCatalogItems($items, $sizeOrder);
            $trees = $inventory->groupCatalogBrowse($items, $sizeOrder);

            return [
                'items' => $items,
                'groups' => $trees['groups'],
                'size_groups' => $trees['size_groups'],
                'categories' => $trees['categories'],
                'view' => 'grouped',
                'units' => $units,
                'filters' => $filters,
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $items->count(),
                    'total' => $items->count(),
                    'from' => $items->isEmpty() ? null : 1,
                    'to' => $items->isEmpty() ? null : $items->count(),
                    'has_more' => false,
                    'next_page' => null,
                    'prev_page' => null,
                ],
            ];
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);
        $page = $this->catalogQuery($request, applyOrderUnit: true)->latest('id')->paginate($perPage);
        $items = collect($page->items())
            ->map(fn (Product $product) => $mapProduct($product))
            ->values();
        $items = $this->sortCatalogItems($items, $sizeOrder);
        $trees = $inventory->groupCatalogBrowse($items, $sizeOrder);

        return [
            'items' => $items,
            'groups' => $trees['groups'],
            'size_groups' => $trees['size_groups'],
            'categories' => $trees['categories'],
            'view' => 'paginated',
            'units' => $units,
            'filters' => $filters,
            'pagination' => $this->paginationMeta($page),
        ];
    }

    protected function wantsGroupedBrowse(Request $request, ?string $family): bool
    {
        if ($request->boolean('grouped')) {
            return true;
        }

        $view = strtolower((string) $request->query('view', ''));
        if (in_array($view, ['grouped', 'tags', 'tree', 'accessories'], true)) {
            return true;
        }

        // System browse (category_id) + ملحقات → full tag accordion sorted by number in tag.
        if ($family === 'accessory') {
            return true;
        }

        return $request->filled('category_id') && $family !== 'pipe';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    protected function sortCatalogItems(Collection $items, string $sizeOrder = 'asc'): Collection
    {
        $sizeOrder = ProductSizeSorter::normalizeOrder($sizeOrder);

        return $items
            ->sort(fn (array $a, array $b) => ProductSizeSorter::compareRows($a, $b, $sizeOrder))
            ->values();
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
            ->with(['translations', 'images', 'tags', 'sizes', 'category.translations', 'category.parent.translations']);

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
        $group = app(NetworkInventoryService::class)->resolveProductGroup($product);
        $isAccessory = $product->isAccessories();
        $size = ProductSizeSorter::resolve(
            $product,
            implode(' ', array_filter([$group['tag'], $group['name'], ...$group['tags']])),
        );

        $item = [
            'id' => (int) $product->id,
            'product_id' => (int) $product->id,
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
            'is_accessory' => $isAccessory,
            'is_accessories' => $isAccessory,
            'product_family' => $isAccessory ? 'accessory' : 'pipe',
            'product_type' => $group['product_type'],
            'product_type_name' => $group['product_type_name'],
            'tags' => $group['tags'],
            'tag' => $group['tag'],
            'group_key' => $group['key'],
            'group_name' => $group['name'],
            'size_value' => $size['size_value'],
            'size_label' => $size['size_label'],
            'size_unit' => $size['size_unit'],
            'size_sort' => $size['size_sort'],
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
        $name = $c->normalizedDisplayName();
        $short = $c->shortDescription();
        $imageUrl = $c->resolvedImageUrl();

        $payload = [
            'id' => (int) $c->id,
            'name' => $name,
            'slug' => $c->slug,
            'image' => $imageUrl ? $c->image : null,
            // Dynamic card icon (Filament → categories/*). Same value twice for app convenience.
            'image_url' => $imageUrl,
            'icon_url' => $imageUrl,
            'badge' => $c->systemBadge(),
            'short_description' => $short,
            'description' => $short,
            'products_count' => (int) $c->products_count + ($includeChildren
                ? (int) $c->children->sum('products_count')
                : 0),
            'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
            'is_accessories' => $c->isAccessoriesFamily(),
        ];

        if ($includeChildren) {
            $payload['children'] = $c->children->map(function (ProductCategory $child) use ($c) {
                $childUrl = $child->resolvedImageUrl();

                return [
                    'id' => (int) $child->id,
                    'name' => $child->normalizedDisplayName(),
                    'slug' => $child->slug,
                    'image' => $childUrl ? $child->image : null,
                    'image_url' => $childUrl,
                    'icon_url' => $childUrl,
                    'badge' => $child->systemBadge(),
                    'short_description' => $child->shortDescription(),
                    'description' => $child->shortDescription(),
                    'products_count' => (int) $child->products_count,
                    'parent_id' => (int) $c->id,
                    'is_accessories' => $child->isAccessoriesFamily(),
                ];
            })->values();
        }

        return $payload;
    }

    protected function storageUrlIfExists(?string $path): ?string
    {
        return \App\Support\PublicStorageUrl::try($path);
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

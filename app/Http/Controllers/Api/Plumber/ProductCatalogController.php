<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Api\Trader\ProductCatalogController as TraderProductCatalogController;
use App\Models\Product;
use App\Models\User;
use App\Services\NetworkInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Plumber order catalog.
 *
 * - Without retail_trader_id → full MG catalog (browse).
 * - With retail_trader_id → full MG catalog in the same order/filters as pipes,
 *   with that shop's available_quantity on every row (0 = visible but not orderable).
 */
class ProductCatalogController extends TraderProductCatalogController
{
    public function index(Request $request): JsonResponse
    {
        $lang = $this->language($request);
        app()->setLocale($lang);

        $stock = null;
        $shopId = null;

        if ($request->filled('retail_trader_id')) {
            $shop = $this->orderableShop((int) $request->query('retail_trader_id'));
            if ($shop instanceof JsonResponse) {
                return $shop;
            }

            $shopId = (int) $shop->id;
            $stock = app(NetworkInventoryService::class)
                ->stockForRetailTrader($shop)
                ->keyBy('product_id');
        }

        $orderUnit = $this->requestedOrderUnit($request);
        $baseQuery = $this->catalogQuery($request, applyOrderUnit: false);

        $boxCount = (clone $baseQuery)->where('pieces_per_box', '>', 0)->count();
        $pieceCount = (clone $baseQuery)->where(function ($q) {
            $q->whereNull('pieces_per_box')->orWhere('pieces_per_box', '<=', 0);
        })->count();

        // Same ordering / filters as trader|distributor pipes catalog.
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $query = $this->catalogQuery($request, applyOrderUnit: true)->latest('id');
        $page = $query->paginate($perPage);

        $items = collect($page->items())->map(function (Product $product) use ($stock) {
            $available = null;
            if ($stock instanceof Collection) {
                $available = (int) ($stock->get($product->id)['available_qty'] ?? 0);
            }

            return $this->mapCatalogProduct($product, $available);
        })->values();

        return $this->success([
            'items' => $items,
            'retail_trader_id' => $shopId,
            'stock_mode' => $shopId !== null,
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
                'retail_trader_id' => $shopId,
            ],
            'pagination' => $this->paginationMeta($page),
        ], $shopId
            ? 'كتالوج MG للمحل — كل المنتجات مع المخزون المتاح'
            : 'كتالوج منتجات MG');
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapCatalogProduct(Product $product, ?int $availableQuantity = null): array
    {
        $item = parent::mapCatalogProduct($product, $availableQuantity);

        if ($availableQuantity !== null) {
            $qty = max(0, $availableQuantity);
            $item['available_quantity'] = $qty;
            $item['max_order_quantity'] = $qty;
            $item['can_order'] = $qty > 0;
            $item['out_of_stock'] = $qty === 0;
        }

        return $item;
    }

    private function orderableShop(int $id): User|JsonResponse
    {
        $shop = User::query()->find($id);

        if (! $shop || ! $shop->isRetailTrader()) {
            return $this->error('المحل غير موجود', 404);
        }

        if (! $shop->is_approved || ! $shop->is_active) {
            return $this->error('لا يمكنك الطلب من هذا المحل', 403);
        }

        return $shop;
    }
}

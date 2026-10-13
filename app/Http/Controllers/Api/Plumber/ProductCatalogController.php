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
 * Plumber order catalog. Without retail_trader_id this is the full MG catalog.
 * With retail_trader_id it is only that shop's in-stock products.
 */
class ProductCatalogController extends TraderProductCatalogController
{
    public function index(Request $request): JsonResponse
    {
        $lang = $this->language($request);
        app()->setLocale($lang);

        $stock = null;
        if ($request->filled('retail_trader_id')) {
            $shop = $this->orderableShop((int) $request->query('retail_trader_id'));
            if ($shop instanceof JsonResponse) {
                return $shop;
            }

            $stock = app(NetworkInventoryService::class)
                ->stockForRetailTrader($shop)
                ->keyBy('product_id');
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $query = $this->catalogQuery($request);

        if ($stock instanceof Collection) {
            $ids = $stock->keys()->map(fn ($id) => (int) $id)->all();
            $query->whereIn('id', $ids === [] ? [0] : $ids);
        }

        $page = $query->latest('id')->paginate($perPage);
        $items = collect($page->items())->map(function (Product $product) use ($stock) {
            $available = $stock instanceof Collection
                ? (int) ($stock->get($product->id)['available_qty'] ?? 0)
                : null;

            return $this->mapCatalogProduct($product, $available);
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

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
 * - Always piece quantities (plumbers never order by the box).
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
            // Qty-only map — avoids building full POS stock rows/slots on every catalog hit.
            $stock = app(NetworkInventoryService::class)
                ->stockQtyByProductForRetailTrader($shop);
        }

        // Default larger pages; allow up to 200 or a single full dump via per_page=all.
        if (! $request->filled('per_page')) {
            $request->merge(['per_page' => 100]);
        } elseif (in_array(strtolower((string) $request->query('per_page')), ['all', '0'], true)) {
            $request->merge(['per_page' => 500]);
        }

        $payload = $this->buildCatalogPayload($request, function (Product $product) use ($stock) {
            $available = null;
            if ($stock instanceof Collection) {
                $available = (int) ($stock->get($product->id) ?? 0);
            }

            return $this->mapCatalogProduct($product, $available);
        }, maxPerPage: 200);

        $payload['retail_trader_id'] = $shopId;
        $payload['stock_mode'] = $shopId !== null;
        $payload['filters']['retail_trader_id'] = $shopId;

        $page = (int) ($payload['pagination']['current_page'] ?? 1);
        $groups = $payload['groups'] ?? [];
        $sizeGroups = $payload['size_groups'] ?? $groups;
        $filters = $payload['filters'] ?? [];

        // Meta trees only on page 1 — later pages are items + pagination.
        if ($page > 1) {
            $groups = [];
            $sizeGroups = [];
            $payload['groups'] = [];
            $payload['size_groups'] = [];
            $payload['categories'] = [];
            $filters = array_filter([
                'retail_trader_id' => $shopId,
                'order_unit' => $filters['order_unit'] ?? 'piece',
            ], fn ($v) => $v !== null);
        }

        return response()->json([
            'status' => true,
            'message' => $shopId
                ? 'كتالوج MG للمحل — تاجات مرتّبة حسب الرقم'
                : 'كتالوج منتجات MG — تاجات مرتّبة حسب الرقم',
            'data' => $payload,
            'items' => $payload['items'] ?? [],
            'groups' => $groups,
            'size_groups' => $sizeGroups,
            'filters' => $filters,
        ])->header('Content-Language', $lang);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapCatalogProduct(Product $product, ?int $availableQuantity = null): array
    {
        $item = parent::mapCatalogProduct($product, $availableQuantity);

        // Plumbers always order by the piece — never by the box.
        // pieces_per_box stays informational (how stock is packed at the shop).
        $item['order_unit'] = 'piece';
        $item['order_by'] = 'piece';

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

<?php

namespace App\Http\Controllers\Api\Distributor;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\InteractsWithOrders;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource as OrderApiResource;
use App\Http\Controllers\Api\Trader\ProductCatalogController;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\NetworkInventoryService;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Wholesale distributor orders:
 *  - places orders to factory or central warehouse
 *  - fulfils orders from its retail traders (channel = wholesale_to_retail, supplier = me)
 *  - central warehouse fulfils warehouse_to_wholesale incoming
 */
class OrderController extends Controller
{
    use ApiResponds;
    use InteractsWithOrders;

    public function __construct(
        protected OrderService $orders,
        protected NetworkInventoryService $inventory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $request->string('scope')->toString();

        $orders = Order::query()
            ->forNetworkUser($user)
            ->with(['items', 'requester:id,name,brand_name,role', 'supplier:id,name,brand_name,role'])
            ->when($scope === 'placed' || $scope === 'to_factory', fn ($q) => $q
                ->placedBy($user)
                ->whereIn('channel', [
                    OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE,
                    OrderStatus::CHANNEL_WAREHOUSE_TO_WHOLESALE,
                ]))
            ->when($scope === 'incoming' || $scope === 'from_retail', fn ($q) => $q
                ->incomingFor($user)
                ->whereIn('channel', [
                    OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL,
                    OrderStatus::CHANNEL_WAREHOUSE_TO_WHOLESALE,
                ]))
            ->when($scope === 'needs_action', fn ($q) => $q
                ->incomingFor($user)
                ->whereIn('channel', [
                    OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL,
                    OrderStatus::CHANNEL_WAREHOUSE_TO_WHOLESALE,
                ])
                ->whereIn('status', [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPING]))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->latest()
            ->get();

        return $this->success(OrderApiResource::collection($orders));
    }

    /** Available supply sources: factory + central warehouses. */
    public function sources(Request $request): JsonResponse
    {
        $warehouses = User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_central_warehouse', true)
            ->where('is_approved', true)
            ->where('is_active', true)
            ->orderBy('brand_name')
            ->orderBy('name')
            ->get(['id', 'name', 'brand_name', 'phone', 'network_code', 'is_central_warehouse']);

        $catalogUrl = url('/api/v1/mobile/distributor/products');

        $items = [
            [
                'source' => 'factory',
                'id' => null,
                'name' => 'المصنع',
                'label' => 'طلب توريد من المصنع',
                'requires_warehouse_id' => false,
                'same_inventory_as_warehouse' => true,
                'catalog_url' => $catalogUrl,
            ],
        ];

        foreach ($warehouses as $wh) {
            // Don't list yourself as a source.
            if ((int) $wh->id === (int) $request->user()->id) {
                continue;
            }

            $items[] = [
                'source' => 'warehouse',
                'id' => (int) $wh->id,
                'warehouse_id' => (int) $wh->id,
                'name' => $wh->brand_name ?: $wh->name,
                'label' => ($wh->brand_name ?: $wh->name).($wh->network_code ? " — {$wh->network_code}" : ''),
                'phone' => $wh->phone,
                'network_code' => $wh->network_code,
                'requires_warehouse_id' => true,
                // المصنع والمخزن واحد — نفس كتالوج المنتجات + كميات المخزون
                'same_inventory_as_factory' => true,
                'catalog_url' => $catalogUrl,
                'stock_url' => url("/api/v1/mobile/distributor/warehouses/{$wh->id}/stock"),
            ];
        }

        return $this->success(['items' => $items], 'مصادر التوريد المتاحة');
    }

    /**
     * Central warehouse stock = same MG product catalog as factory,
     * overlaid with available_qty from warehouse slots (null = same as factory / unlimited pool).
     */
    public function warehouseStock(Request $request, User $warehouse): JsonResponse
    {
        if (! $warehouse->isCentralWarehouse()) {
            return $this->error('هذا الحساب ليس مخزناً مركزياً', 422);
        }

        if (! $warehouse->is_active || ! $warehouse->is_approved) {
            return $this->error('هذا المخزن غير مفعّل حالياً', 403);
        }

        $catalog = app(ProductCatalogController::class);
        $lang = substr(strtolower(str_replace('_', '-', (string) $request->header('Accept-Language', 'ar'))), 0, 2);
        app()->setLocale(in_array($lang, ['ar', 'en'], true) ? $lang : 'ar');

        $orderUnit = strtolower(trim((string) ($request->query('order_unit') ?? $request->query('unit') ?? '')));
        $orderUnit = in_array($orderUnit, ['box', 'piece'], true) ? $orderUnit : null;
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $stockByProduct = $this->inventory->stockForWholesaler($warehouse)->keyBy('product_id');

        $query = Product::query()->with(['translations', 'images', 'category.translations']);

        if ($request->filled('category_id')) {
            $query->where('product_category_id', (int) $request->query('category_id'));
        }
        if ($request->filled('q') || $request->filled('search')) {
            $query->search((string) ($request->query('q') ?? $request->query('search')));
        }
        if ($orderUnit === 'box') {
            $query->where('pieces_per_box', '>', 0);
        } elseif ($orderUnit === 'piece') {
            $query->where(function ($q) {
                $q->whereNull('pieces_per_box')->orWhere('pieces_per_box', '<=', 0);
            });
        }

        $boxCount = (clone $query)->where('pieces_per_box', '>', 0)->count();
        $pieceCount = Product::query()
            ->when($request->filled('category_id'), fn ($q) => $q->where('product_category_id', (int) $request->query('category_id')))
            ->where(function ($q) {
                $q->whereNull('pieces_per_box')->orWhere('pieces_per_box', '<=', 0);
            })
            ->count();

        $page = $query->latest('id')->paginate($perPage);

        $items = collect($page->items())->map(function (Product $product) use ($stockByProduct, $catalog) {
            $slot = $stockByProduct->get($product->id);
            $qty = $slot !== null ? (int) ($slot['available_qty'] ?? 0) : null;
            $mapped = $catalog->mapCatalogProductPublic($product, $qty);

            return [
                'product_id' => (int) $product->id,
                'id' => (int) $product->id,
                'name' => $mapped['name'],
                'image' => $mapped['image'] ?? null,
                'image_url' => $mapped['image_url'] ?? null,
                'category_id' => $mapped['category_id'] ?? null,
                'points_per_unit' => null,
                'has_points' => (bool) ($mapped['has_points'] ?? false),
                'pieces_per_box' => $mapped['pieces_per_box'] ?? null,
                'order_unit' => $mapped['order_unit'] ?? 'piece',
                // null = نفس مخزون المصنع (غير محدود في العرض) ؛ رقم = كمية مسجّلة في المخزن
                'available_qty' => $qty,
                'available_quantity' => $qty,
                'same_as_factory' => true,
            ];
        })->values();

        return $this->success([
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->brand_name ?: $warehouse->name,
                'phone' => $warehouse->phone,
                'network_code' => $warehouse->network_code,
            ],
            'same_as_factory' => true,
            'items' => $items,
            'units' => [
                'all' => $boxCount + $pieceCount,
                'box' => $boxCount,
                'piece' => $pieceCount,
            ],
            'filters' => [
                'order_unit' => $orderUnit,
                'category_id' => $request->filled('category_id') ? (int) $request->query('category_id') : null,
                'search' => $request->query('q') ?? $request->query('search'),
            ],
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'has_more' => $page->hasMorePages(),
                'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
                'prev_page' => $page->currentPage() > 1 ? $page->currentPage() - 1 : null,
            ],
        ], 'مخزون المخزن = كتالوج المصنع (نفس المنتجات)');
    }

    /**
     * Preview cart totals before placing — returns total_points only (no product points).
     */
    public function quote(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.boxes' => ['nullable', 'integer', 'min:1'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $v->after(function ($validator) use ($request) {
            foreach ($request->input('lines', []) as $i => $line) {
                $boxes = $line['boxes'] ?? null;
                $qty = $line['quantity'] ?? null;
                if (($boxes === null || (int) $boxes < 1) && ($qty === null || (int) $qty < 1)) {
                    $validator->errors()->add("lines.$i", 'أرسل عدد الصناديق (boxes) أو الكمية بالقطع (quantity).');
                }
            }
        });

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        try {
            $quote = $this->orders->quote(
                lines: $request->input('lines'),
                enforceFactoryBoxes: true,
            );

            return $this->success($quote, 'ملخص السلة — مجموع النقاط فقط');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'source' => ['nullable', 'string', 'in:factory,warehouse'],
            'warehouse_id' => ['nullable', 'integer', 'exists:users,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.boxes' => ['nullable', 'integer', 'min:1'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $v->after(function ($validator) use ($request) {
            foreach ($request->input('lines', []) as $i => $line) {
                $boxes = $line['boxes'] ?? null;
                $qty = $line['quantity'] ?? null;
                if (($boxes === null || (int) $boxes < 1) && ($qty === null || (int) $qty < 1)) {
                    $validator->errors()->add("lines.$i", 'أرسل عدد الصناديق (boxes) أو الكمية بالقطع (quantity).');
                }
            }

            $source = $request->input('source', 'factory');
            if ($source === 'warehouse' && ! $request->filled('warehouse_id')) {
                $validator->errors()->add('warehouse_id', 'warehouse_id مطلوب عند source=warehouse');
            }
        });

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        $source = $request->input('source', 'factory');
        $channel = $source === 'warehouse'
            ? OrderStatus::CHANNEL_WAREHOUSE_TO_WHOLESALE
            : OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE;

        try {
            $order = $this->orders->place(
                requester: $request->user(),
                channel: $channel,
                lines: $request->input('lines'),
                meta: [
                    'note' => $request->input('note'),
                    'warehouse_id' => $request->input('warehouse_id'),
                    'supplier_id' => $request->input('warehouse_id'),
                ],
            );

            $msg = $source === 'warehouse'
                ? 'تم إرسال الطلب للمخزن المركزي'
                : 'تم إرسال الطلب للمصنع';

            return $this->created(new OrderApiResource($this->loadedOrder($order)), $msg);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        if (! $this->isParty($order, $request->user())) {
            return $this->error('غير مصرّح لك بعرض هذا الطلب', 403);
        }

        return $this->success(new OrderApiResource($this->loadedOrder($order)));
    }

    public function confirm(Request $request, Order $order): JsonResponse
    {
        if (! $this->isSupplier($order, $request->user())) {
            return $this->error('يمكن للمورّد فقط تأكيد الطلب', 403);
        }

        return $this->transition(
            fn () => $this->orders->confirm($order, $request->user(), $request->input('note')),
            'تم تأكيد الطلب',
        );
    }

    public function ship(Request $request, Order $order): JsonResponse
    {
        if (! $this->isSupplier($order, $request->user())) {
            return $this->error('يمكن للمورّد فقط شحن الطلب', 403);
        }

        $v = Validator::make($request->all(), [
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'expected_delivery_at' => ['nullable', 'date'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        return $this->transition(
            fn () => $this->orders->ship($order, $request->user(), $v->validated()),
            'تم شحن الطلب',
        );
    }

    public function deliver(Request $request, Order $order): JsonResponse
    {
        // Receipt is always confirmed by the buyer (receive). Kept for old clients → clear error.
        return $this->error(
            'تأكيد الاستلام يتم من الطرف المستلم فقط — استخدم POST …/receive من حساب المشتري',
            403,
        );
    }

    public function reject(Request $request, Order $order): JsonResponse
    {
        if (! $this->isSupplier($order, $request->user())) {
            return $this->error('يمكن للمورّد فقط رفض الطلب', 403);
        }

        return $this->transition(
            fn () => $this->orders->reject($order, $request->user(), $request->input('reason')),
            'تم رفض الطلب',
        );
    }

    public function receive(Request $request, Order $order): JsonResponse
    {
        if (! $this->isBuyer($order, $request->user())) {
            return $this->error('يمكن لصاحب الطلب فقط تأكيد الاستلام', 403);
        }

        return $this->transition(
            fn () => $this->orders->deliver($order, $request->user()),
            'تم تأكيد الاستلام وإضافة الكميات لمخزونك',
        );
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        if (! $this->isBuyer($order, $request->user())) {
            return $this->error('يمكن لصاحب الطلب فقط الإلغاء', 403);
        }

        return $this->transition(
            fn () => $this->orders->cancel($order, $request->user(), $request->input('reason')),
            'تم إلغاء الطلب',
        );
    }

    protected function transition(callable $fn, string $message): JsonResponse
    {
        try {
            $order = $fn();

            return $this->success(new OrderApiResource($this->loadedOrder($order)), $message);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

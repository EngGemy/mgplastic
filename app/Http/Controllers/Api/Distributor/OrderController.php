<?php

namespace App\Http\Controllers\Api\Distributor;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\InteractsWithOrders;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource as OrderApiResource;
use App\Models\Order;
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

        $items = [
            [
                'source' => 'factory',
                'id' => null,
                'name' => 'المصنع',
                'label' => 'طلب توريد من المصنع',
                'requires_warehouse_id' => false,
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
                'stock_url' => url("/api/v1/mobile/distributor/warehouses/{$wh->id}/stock"),
            ];
        }

        return $this->success(['items' => $items], 'مصادر التوريد المتاحة');
    }

    /** Central warehouse remaining stock — for placing supply orders. */
    public function warehouseStock(Request $request, User $warehouse): JsonResponse
    {
        if (! $warehouse->isCentralWarehouse()) {
            return $this->error('هذا الحساب ليس مخزناً مركزياً', 422);
        }

        if (! $warehouse->is_active || ! $warehouse->is_approved) {
            return $this->error('هذا المخزن غير مفعّل حالياً', 403);
        }

        $stock = $this->inventory->stockForWholesaler($warehouse);

        $items = $stock->map(fn (array $row) => [
            'product_id' => (int) $row['product_id'],
            'name' => $row['name'],
            'image' => $row['image'] ?? null,
            'image_url' => $row['image'] ?? null,
            'category_id' => $row['category_id'] ?? null,
            'points_per_unit' => null,
            'has_points' => ((float) ($row['points_per_unit'] ?? 0)) > 0,
            'pieces_per_box' => isset($row['pieces_per_box']) ? (int) $row['pieces_per_box'] : null,
            'available_qty' => (int) ($row['available_qty'] ?? 0),
            'available_quantity' => (int) ($row['available_qty'] ?? 0),
        ])->values();

        return $this->success([
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->brand_name ?: $warehouse->name,
                'phone' => $warehouse->phone,
                'network_code' => $warehouse->network_code,
            ],
            'items' => $items,
        ], 'مخزون المخزن المتاح');
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
        if (! $this->isSupplier($order, $request->user()) || $order->isPlumberChannel()) {
            return $this->error('يمكن للمورّد فقط تسليم هذا الطلب', 403);
        }

        if (! in_array($order->status, [OrderStatus::CONFIRMED, OrderStatus::SHIPPING], true)) {
            return $this->error('لا يمكن التسليم قبل تأكيد أو شحن الطلب', 422);
        }

        return $this->transition(
            fn () => $this->orders->deliver($order, $request->user()),
            'تم تسليم الطلب وإضافة الكميات لمخزون المشتري',
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

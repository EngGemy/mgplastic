<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\InteractsWithOrders;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource as OrderApiResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Retail trader orders:
 *  - places orders to wholesale (channel = wholesale_to_retail)
 *  - fulfils orders from plumbers (channel = retail_to_plumber, supplier = me)
 */
class OrderController extends Controller
{
    use ApiResponds;
    use InteractsWithOrders;

    public function __construct(protected OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $request->string('scope')->toString();

        $orders = Order::query()
            ->forNetworkUser($user)
            ->with(['items', 'requester:id,name,brand_name,role', 'supplier:id,name,brand_name,role'])
            ->when($scope === 'placed', fn ($q) => $q->placedBy($user)->where('channel', OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL))
            ->when($scope === 'incoming', fn ($q) => $q->incomingFor($user)->where('channel', OrderStatus::CHANNEL_RETAIL_TO_PLUMBER))
            ->when($scope === 'to_wholesaler', fn ($q) => $q->placedBy($user)->where('channel', OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL))
            ->when($scope === 'from_plumbers', fn ($q) => $q->incomingFor($user)->where('channel', OrderStatus::CHANNEL_RETAIL_TO_PLUMBER))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->latest()
            ->get();

        return $this->success(OrderApiResource::collection($orders));
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        try {
            $order = $this->orders->place(
                requester: $request->user(),
                channel: OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL,
                lines: $request->input('lines'),
                meta: ['note' => $request->input('note')],
            );

            return $this->created(new OrderApiResource($this->loadedOrder($order)), 'تم إرسال الطلب لموزّع الجملة');
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
        if (! $this->isSupplier($order, $request->user()) || ! $order->isPlumberChannel()) {
            return $this->error('يمكن للتاجر القطاعي فقط تأكيد طلبات السباكين', 403);
        }

        return $this->transition(
            fn () => $this->orders->confirm($order, $request->user(), $request->input('note')),
            'تم تأكيد الطلب',
        );
    }

    public function ship(Request $request, Order $order): JsonResponse
    {
        if (! $this->isSupplier($order, $request->user()) || ! $order->isPlumberChannel()) {
            return $this->error('يمكن للتاجر القطاعي فقط شحن طلبات السباكين', 403);
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

    public function reject(Request $request, Order $order): JsonResponse
    {
        if (! $this->isSupplier($order, $request->user()) || ! $order->isPlumberChannel()) {
            return $this->error('يمكن للتاجر القطاعي فقط رفض طلبات السباكين', 403);
        }

        return $this->transition(
            fn () => $this->orders->reject($order, $request->user(), $request->input('reason')),
            'تم رفض الطلب',
        );
    }

    public function stock(Request $request, Order $order): JsonResponse
    {
        if (! $this->isParty($order, $request->user())) {
            return $this->error('غير مصرّح', 403);
        }

        if (! $order->isPlumberChannel()) {
            return $this->error('فحص المخزون لطلبات السباكين فقط', 422);
        }

        return $this->success([
            'order_id' => $order->id,
            'lines' => $this->orders->stockAvailability($order),
        ]);
    }

    public function updateItems(Request $request, Order $order): JsonResponse
    {
        if (! $this->isSupplier($order, $request->user()) || ! $order->isPlumberChannel()) {
            return $this->error('يمكن للتاجر القطاعي فقط تعديل طلبات السباكين', 403);
        }

        $v = Validator::make($request->all(), [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        return $this->transition(
            fn () => $this->orders->updateItems($order, $request->user(), $request->input('lines')),
            'تم تحديث أصناف الطلب',
        );
    }

    public function applyStock(Request $request, Order $order): JsonResponse
    {
        if (! $this->isSupplier($order, $request->user()) || ! $order->isPlumberChannel()) {
            return $this->error('يمكن للتاجر القطاعي فقط تطبيق المخزون', 403);
        }

        return $this->transition(
            fn () => $this->orders->applyAvailableStock($order, $request->user()),
            'تم تطبيق المتوفر من المخزون',
        );
    }

    public function fulfill(Request $request, Order $order): JsonResponse
    {
        if (! $this->isSupplier($order, $request->user()) || ! $order->isPlumberChannel()) {
            return $this->error('يمكن للتاجر القطاعي فقط تنفيذ الطلب كفاتورة', 403);
        }

        return $this->transition(
            fn () => $this->orders->fulfillAsInvoice($order, $request->user(), $request->input('note')),
            'تم تنفيذ الطلب وتحويله لفاتورة وتحديث المخزون',
        );
    }

    public function receive(Request $request, Order $order): JsonResponse
    {
        if (! $this->isBuyer($order, $request->user())) {
            return $this->error('يمكن لصاحب الطلب فقط تأكيد الاستلام', 403);
        }

        return $this->transition(fn () => $this->orders->deliver($order, $request->user()), 'تم تأكيد الاستلام وإضافة الكميات لمخزونك');
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        if (! $this->isBuyer($order, $request->user())) {
            return $this->error('يمكن لصاحب الطلب فقط الإلغاء', 403);
        }

        return $this->transition(fn () => $this->orders->cancel($order, $request->user(), $request->input('reason')), 'تم إلغاء الطلب');
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

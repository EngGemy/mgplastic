<?php

namespace App\Http\Controllers\Api\Distributor;

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
 * Wholesale distributor orders:
 *  - places orders to the factory (channel = factory_to_wholesale)
 *  - fulfils orders from its retail traders (channel = wholesale_to_retail, supplier = me)
 */
class OrderController extends Controller
{
    use ApiResponds;
    use InteractsWithOrders;

    public function __construct(protected OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $orders = Order::query()
            ->forNetworkUser($user)
            ->with(['items', 'requester:id,name,brand_name', 'supplier:id,name,brand_name'])
            ->when($request->string('scope')->toString() === 'placed', fn ($q) => $q->placedBy($user))
            ->when($request->string('scope')->toString() === 'incoming', fn ($q) => $q->incomingFor($user))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
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
                channel: OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE,
                lines: $request->input('lines'),
                meta: ['note' => $request->input('note')],
            );

            return $this->created(new OrderApiResource($this->loadedOrder($order)), 'تم إرسال الطلب للمصنع');
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

        return $this->transition(fn () => $this->orders->confirm($order, $request->user(), $request->input('note')), 'تم تأكيد الطلب');
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

    public function reject(Request $request, Order $order): JsonResponse
    {
        if (! $this->isSupplier($order, $request->user())) {
            return $this->error('يمكن للمورّد فقط رفض الطلب', 403);
        }

        return $this->transition(fn () => $this->orders->reject($order, $request->user(), $request->input('reason')), 'تم رفض الطلب');
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

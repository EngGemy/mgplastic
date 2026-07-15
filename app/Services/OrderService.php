<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        protected WholesaleInvoiceService $wholesaleInvoices,
        protected NetworkInventoryService $inventory,
        protected DistributionService $distributions,
    ) {}

    /**
     * Place a new order (buyer initiated).
     *
     * @param  array<int, array{product_id:int, quantity:int}>  $lines
     * @param  array{note?:?string}  $meta
     *
     * @throws \DomainException
     */
    public function place(User $requester, string $channel, array $lines, array $meta = []): Order
    {
        $supplier = $this->resolveSupplier($requester, $channel);
        $prepared = $this->prepareLines($lines);

        if (empty($prepared['items'])) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل للطلب');
        }

        $order = DB::transaction(function () use ($requester, $supplier, $channel, $prepared, $meta) {
            $order = Order::create([
                'channel' => $channel,
                'requester_id' => $requester->id,
                'supplier_id' => $supplier?->id,
                'status' => OrderStatus::PLACED,
                'total_quantity' => $prepared['total_quantity'],
                'total_points' => $prepared['total_points'],
                'note' => $meta['note'] ?? null,
                'placed_at' => now(),
                'created_by' => auth()->id() ?? $requester->id,
            ]);

            $order->update([
                'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), $order->id),
            ]);

            foreach ($prepared['items'] as $row) {
                OrderItem::create(['order_id' => $order->id, ...$row]);
            }

            return $order;
        });

        $this->notifyPlaced($order->fresh(['requester', 'supplier']));

        return $order->load('items');
    }

    /** Supplier accepts the order. */
    public function confirm(Order $order, User $actor, ?string $note = null): Order
    {
        $this->assertStatus($order, [OrderStatus::PLACED], 'لا يمكن تأكيد هذا الطلب في حالته الحالية');

        $order->update([
            'status' => OrderStatus::CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by' => $actor->id,
            'supplier_note' => $note ?: $order->supplier_note,
        ]);

        $this->notifyRequester(
            $order,
            'تم تأكيد طلبك ✓',
            "طلب رقم {$order->order_number} تم تأكيده ويجري تجهيزه.",
            'success',
        );

        return $order;
    }

    /**
     * Supplier ships the order.
     *
     * @param  array{carrier_name?:?string, tracking_number?:?string, expected_delivery_at?:?string}  $shipping
     */
    public function ship(Order $order, User $actor, array $shipping = []): Order
    {
        $this->assertStatus($order, [OrderStatus::CONFIRMED, OrderStatus::PLACED], 'لا يمكن شحن هذا الطلب في حالته الحالية');

        $order->update([
            'status' => OrderStatus::SHIPPING,
            'shipped_at' => now(),
            'shipped_by' => $actor->id,
            'confirmed_at' => $order->confirmed_at ?? now(),
            'carrier_name' => $shipping['carrier_name'] ?? $order->carrier_name,
            'tracking_number' => $shipping['tracking_number'] ?? $order->tracking_number,
            'expected_delivery_at' => $shipping['expected_delivery_at'] ?? $order->expected_delivery_at,
        ]);

        $tracking = $order->tracking_number ? " — رقم التتبّع: {$order->tracking_number}" : '';

        $this->notifyRequester(
            $order,
            'طلبك في الطريق 🚚',
            "طلب رقم {$order->order_number} تم شحنه وهو في طريقه إليك{$tracking}.",
            'info',
        );

        return $order;
    }

    /**
     * Buyer confirms receipt — this is where stock is added to the buyer via the
     * existing distribution pipeline.
     *
     * @throws \DomainException
     */
    public function deliver(Order $order, User $actor): Order
    {
        $this->assertStatus(
            $order,
            [OrderStatus::SHIPPING, OrderStatus::CONFIRMED],
            'لا يمكن تأكيد الاستلام قبل شحن الطلب',
        );

        return DB::transaction(function () use ($order, $actor) {
            $order->loadMissing('items', 'requester', 'supplier');

            $reference = $this->fulfilStock($order, $actor);

            $order->update([
                'status' => OrderStatus::DELIVERED,
                'delivered_at' => now(),
                'delivered_by' => $actor->id,
                'delivered_invoice_id' => $reference['invoice_id'] ?? null,
                'delivered_reference' => $reference['reference'] ?? null,
            ]);

            $this->notifySupplierAndAdmins(
                $order,
                'تم تسليم الطلب ✓',
                "طلب رقم {$order->order_number} تم استلامه وتأكيده من {$order->requester?->name}.",
                'success',
            );

            return $order;
        });
    }

    /** Supplier rejects the order. */
    public function reject(Order $order, User $actor, ?string $reason = null): Order
    {
        $this->assertStatus($order, [OrderStatus::PLACED, OrderStatus::CONFIRMED], 'لا يمكن رفض هذا الطلب في حالته الحالية');

        $order->update([
            'status' => OrderStatus::REJECTED,
            'cancelled_at' => now(),
            'supplier_note' => $reason ?: $order->supplier_note,
        ]);

        $this->notifyRequester(
            $order,
            'تم رفض الطلب',
            "طلب رقم {$order->order_number} تم رفضه".($reason ? " — السبب: {$reason}" : '').'.',
            'danger',
        );

        return $order;
    }

    /** Buyer cancels their own order before it is shipped. */
    public function cancel(Order $order, User $actor, ?string $reason = null): Order
    {
        $this->assertStatus($order, [OrderStatus::PLACED, OrderStatus::CONFIRMED], 'لا يمكن إلغاء الطلب بعد شحنه');

        $order->update([
            'status' => OrderStatus::CANCELLED,
            'cancelled_at' => now(),
            'note' => $reason ? trim(($order->note ? $order->note."\n" : '').'إلغاء: '.$reason) : $order->note,
        ]);

        $this->notifySupplierAndAdmins(
            $order,
            'تم إلغاء طلب',
            "طلب رقم {$order->order_number} أُلغي من قبل {$order->requester?->name}.",
            'warning',
        );

        return $order;
    }

    // ── internals ──────────────────────────────────────────────

    protected function resolveSupplier(User $requester, string $channel): ?User
    {
        if ($channel === OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE) {
            if (! $requester->isWholesaleDistributor()) {
                throw new \DomainException('الطلب من المصنع متاح لموزّعي الجملة فقط');
            }

            return null; // factory / admin side
        }

        if ($channel === OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL) {
            if (! $requester->isRetailTrader()) {
                throw new \DomainException('الطلب من الجملة متاح لتجّار القطاعي فقط');
            }

            $supplier = $requester->parent_distributor_id
                ? User::find($requester->parent_distributor_id)
                : null;

            if (! $supplier || ! $supplier->isWholesaleDistributor()) {
                throw new \DomainException('لست مرتبطاً بموزّع جملة — تواصل مع الإدارة لربط حسابك');
            }

            return $supplier;
        }

        throw new \DomainException('نوع الطلب غير صالح');
    }

    /**
     * @param  array<int, array{product_id:int, quantity:int}>  $lines
     * @return array{items:array<int, array<string, mixed>>, total_quantity:int, total_points:int}
     */
    protected function prepareLines(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = (int) ($line['quantity'] ?? 0);

            if ($productId <= 0 || $qty <= 0) {
                continue;
            }

            $merged[$productId] = ($merged[$productId] ?? 0) + $qty;
        }

        $items = [];
        $totalQty = 0;
        $totalPoints = 0;

        foreach ($merged as $productId => $qty) {
            $product = Product::with('translations')->find($productId);

            if (! $product) {
                continue;
            }

            $ppu = (float) ($product->points_per_unit ?? 0);
            $linePoints = (int) floor($qty * $ppu);

            $items[] = [
                'product_id' => $productId,
                'name_snapshot' => localized_name($product, 'name', "منتج #{$productId}"),
                'quantity' => $qty,
                'points_per_unit' => $ppu,
                'line_points' => $linePoints,
            ];

            $totalQty += $qty;
            $totalPoints += $linePoints;
        }

        return [
            'items' => $items,
            'total_quantity' => $totalQty,
            'total_points' => $totalPoints,
        ];
    }

    /**
     * Add the ordered quantities to the buyer's derived stock by reusing the
     * existing invoice/distribution services.
     *
     * @return array{invoice_id?:?int, reference?:?array<string, mixed>}
     */
    protected function fulfilStock(Order $order, User $actor): array
    {
        $lines = $order->items->map(fn (OrderItem $item) => [
            'product_id' => (int) $item->product_id,
            'quantity' => (int) $item->quantity,
            'points_per_unit' => (float) $item->points_per_unit,
        ])->all();

        if ($order->isFactoryChannel()) {
            $wholesaler = $order->requester;

            if (! $wholesaler) {
                throw new \DomainException('تعذّر تحديد موزّع الجملة صاحب الطلب');
            }

            $invoice = $this->wholesaleInvoices->issueFromCart(
                wholesaler: $wholesaler,
                lines: $lines,
                issuedBy: $actor,
            );

            return [
                'invoice_id' => $invoice->id,
                'reference' => ['invoice_number' => $invoice->number],
            ];
        }

        // wholesale → retail
        $wholesaler = $order->supplier;
        $retailTrader = $order->requester;

        if (! $wholesaler || ! $retailTrader) {
            throw new \DomainException('تعذّر تحديد أطراف الطلب');
        }

        if ((int) $retailTrader->parent_distributor_id !== (int) $wholesaler->id) {
            throw new \DomainException('هذا التاجر القطاعي غير تابع لموزّع الجملة');
        }

        $stock = $this->inventory->stockForWholesaler($wholesaler);

        if ($stock->isEmpty()) {
            throw new \DomainException('مخزون موزّع الجملة فارغ حالياً — تعذّر تسليم الطلب');
        }

        $requested = [];
        foreach ($lines as $line) {
            $requested[$line['product_id']] = ($requested[$line['product_id']] ?? 0) + (int) $line['quantity'];
        }

        // Throws a clear message if the wholesaler no longer has enough stock.
        $groups = $this->inventory->allocateFromStock($stock, $requested);

        $invoiceNumbers = [];

        foreach ($groups as $group) {
            $invoice = Invoice::query()->findOrFail($group['invoice_id']);

            $distribution = $this->distributions->createDistribution(
                invoice: $invoice,
                fromUser: $wholesaler,
                toUser: $retailTrader,
                tier: 2,
                items: $group['items'],
                parentId: $group['parent_distribution_id'],
                skipCallerCheck: true, // the buyer confirms receipt, not the seller
            );

            $this->distributions->confirmDistribution($distribution->fresh(['items']));

            $outgoing = Invoice::query()->where('source_distribution_id', $distribution->id)->first();

            if ($outgoing?->number) {
                $invoiceNumbers[] = $outgoing->number;
            }
        }

        return [
            'invoice_id' => null,
            'reference' => ['invoice_numbers' => $invoiceNumbers],
        ];
    }

    protected function assertStatus(Order $order, array $allowed, string $message): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw new \DomainException($message);
        }
    }

    protected function notifyPlaced(Order $order): void
    {
        $title = 'طلب جديد 🛎️';
        $body = "طلب رقم {$order->order_number} من {$order->requester?->name} — {$order->total_quantity} وحدة.";

        if ($order->isFactoryChannel()) {
            AdminNotificationService::sendToRole('super_admin', $title, $body, 'info');
            AdminNotificationService::sendToRole('admin', $title, $body, 'info');

            return;
        }

        if ($order->supplier) {
            AdminNotificationService::send($order->supplier, $title, $body, 'info');
        }
    }

    protected function notifyRequester(Order $order, string $title, string $body, string $status): void
    {
        if ($order->requester) {
            AdminNotificationService::send($order->requester, $title, $body, $status);
        }
    }

    protected function notifySupplierAndAdmins(Order $order, string $title, string $body, string $status): void
    {
        if ($order->isFactoryChannel()) {
            AdminNotificationService::sendToRole('super_admin', $title, $body, $status);
            AdminNotificationService::sendToRole('admin', $title, $body, $status);

            return;
        }

        if ($order->supplier) {
            AdminNotificationService::send($order->supplier, $title, $body, $status);
        }
    }
}

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
     * @param  array{note?:?string, retail_trader_id?:?int, supplier_id?:?int}  $meta
     *
     * @throws \DomainException
     */
    public function place(User $requester, string $channel, array $lines, array $meta = []): Order
    {
        $supplier = $this->resolveSupplier($requester, $channel, $meta);
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

            // Plumber channel credits points via tier-3 distribution — skip double credit.
            if (! $order->isPlumberChannel()) {
                $this->creditRequesterPoints($order, $actor, $reference['invoice_id'] ?? null);
            }

            $order->update([
                'status' => OrderStatus::DELIVERED,
                'delivered_at' => now(),
                'delivered_by' => $actor->id,
                'delivered_invoice_id' => $reference['invoice_id'] ?? null,
                'delivered_reference' => $reference['reference'] ?? null,
            ]);

            $points = (int) $order->total_points;
            $pointsNote = $points > 0
                ? ($order->isPlumberChannel()
                    ? " وتم إضافة {$points} نقطة لمحفظة السباك."
                    : " وتم إضافة {$points} نقطة لمحفظتك.")
                : '.';

            $this->notifyRequester(
                $order,
                'تم استلام طلبك ✓',
                "طلب رقم {$order->order_number} تم تسليمه{$pointsNote}",
                'success',
            );

            $this->notifySupplierAndAdmins(
                $order,
                'تم تسليم الطلب ✓',
                "طلب رقم {$order->order_number} تم استلامه وتأكيده من {$order->requester?->name}.",
                'success',
            );

            return $order;
        });
    }

    /**
     * عند التسليم: تُضاف نقاط الطلب تلقائياً لمحفظة طالب الطلب
     * (موزّع جملة أو تاجر قطاعي) ليستطيع التوزيع/البيع لاحقاً.
     */
    protected function creditRequesterPoints(Order $order, User $actor, ?int $invoiceId): void
    {
        $points = (int) $order->total_points;
        $requester = $order->requester;

        if ($points <= 0 || ! $requester) {
            return;
        }

        $requester->wallet()->creditPoints($points, [
            'reason' => 'order_delivery',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'invoice_id' => $invoiceId,
        ], $actor, "نقاط تسليم طلب {$order->order_number}");

        if ($invoiceId) {
            Invoice::query()->whereKey($invoiceId)->update([
                'points_awarded' => $points,
            ]);
        }
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

    /**
     * @param  array{retail_trader_id?:?int, supplier_id?:?int}  $meta
     */
    protected function resolveSupplier(User $requester, string $channel, array $meta = []): ?User
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

            $supplier = null;

            if ($requester->parent_distributor_id) {
                $supplier = User::find($requester->parent_distributor_id);
            }

            if ((! $supplier || ! $supplier->isWholesaleDistributor()) && $requester->relationLoaded('linkedWholesalers')) {
                $supplier = $requester->linkedWholesalers->first();
            }

            if (! $supplier || ! $supplier->isWholesaleDistributor()) {
                $supplier = $requester->linkedWholesalers()->first();
            }

            if (! $supplier || ! $supplier->isWholesaleDistributor()) {
                throw new \DomainException('لست مرتبطاً بموزّع جملة — تواصل مع موزّعك ليربط حسابك عبر الرقم الموحّد');
            }

            return $supplier;
        }

        if ($channel === OrderStatus::CHANNEL_RETAIL_TO_PLUMBER) {
            if (! $requester->isPlumber()) {
                throw new \DomainException('طلب المنتجات من التاجر القطاعي متاح للسباكين فقط');
            }

            $supplierId = (int) ($meta['retail_trader_id'] ?? $meta['supplier_id'] ?? 0);

            if ($supplierId <= 0 && $requester->parent_distributor_id) {
                $parent = User::find($requester->parent_distributor_id);
                if ($parent?->isRetailTrader()) {
                    $supplierId = (int) $parent->id;
                }
            }

            $supplier = $supplierId > 0 ? User::find($supplierId) : null;

            if (! $supplier || ! $supplier->isRetailTrader()) {
                throw new \DomainException('اختر التاجر القطاعي (retail_trader_id) لإرسال الطلب إليه');
            }

            if (! $supplier->is_active || ! $supplier->is_approved) {
                throw new \DomainException('هذا التاجر القطاعي غير مفعّل حالياً');
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
            $wholesaler = User::query()->find($order->requester_id);

            // Confirming buyer is the wholesaler — use the actor if the relation is stale.
            if ((! $wholesaler || ! $wholesaler->isWholesaleDistributor())
                && $actor->isWholesaleDistributor()
                && (int) $actor->id === (int) $order->requester_id) {
                $wholesaler = $actor;
            }

            if (! $wholesaler || ! $wholesaler->isWholesaleDistributor()) {
                throw new \DomainException('تعذّر تسليم الطلب: صاحب الطلب ليس موزّع جملة');
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

        if ($order->isPlumberChannel()) {
            return $this->fulfilRetailToPlumber($order, $lines);
        }

        // wholesale → retail
        $wholesaler = $order->supplier;
        $retailTrader = $order->requester;

        if (! $wholesaler || ! $retailTrader) {
            throw new \DomainException('تعذّر تحديد أطراف الطلب');
        }

        if (! app(RetailNetworkLinkService::class)->isLinked($wholesaler, $retailTrader)) {
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

    /**
     * @param  array<int, array{product_id:int, quantity:int, points_per_unit?:float}>  $lines
     * @return array{invoice_id?:?int, reference?:?array<string, mixed>}
     */
    protected function fulfilRetailToPlumber(Order $order, array $lines): array
    {
        $retailTrader = $order->supplier;
        $plumber = $order->requester;

        if (! $retailTrader?->isRetailTrader() || ! $plumber?->isPlumber()) {
            throw new \DomainException('تعذّر تحديد أطراف طلب السباك');
        }

        $stock = $this->inventory->stockForRetailTrader($retailTrader);

        if ($stock->isEmpty()) {
            throw new \DomainException('مخزون التاجر القطاعي فارغ حالياً — تعذّر تسليم الطلب');
        }

        $requested = [];
        foreach ($lines as $line) {
            $requested[$line['product_id']] = ($requested[$line['product_id']] ?? 0) + (int) $line['quantity'];
        }

        $groups = $this->inventory->allocateFromStock($stock, $requested);
        $distributionIds = [];

        foreach ($groups as $group) {
            $invoice = Invoice::query()->findOrFail($group['invoice_id']);

            $distribution = $this->distributions->createDistribution(
                invoice: $invoice,
                fromUser: $retailTrader,
                toUser: $plumber,
                tier: 3,
                items: $group['items'],
                parentId: $group['parent_distribution_id'],
                skipCallerCheck: true,
            );

            $this->distributions->confirmDistribution($distribution->fresh(['items']));
            $distributionIds[] = $distribution->id;
        }

        return [
            'invoice_id' => null,
            'reference' => ['distribution_ids' => $distributionIds],
        ];
    }

    /**
     * Stock check for each line on a plumber→retail order.
     *
     * @return list<array{
     *   product_id:int,
     *   name:string,
     *   requested_qty:int,
     *   available_qty:int,
     *   fulfillable_qty:int,
     *   is_available:bool,
     *   points_per_unit:float
     * }>
     */
    public function stockAvailability(Order $order): array
    {
        if (! $order->isPlumberChannel() || ! $order->supplier) {
            return [];
        }

        $order->loadMissing('items');
        $stock = $this->inventory->stockForRetailTrader($order->supplier)->keyBy('product_id');

        return $order->items->map(function (OrderItem $item) use ($stock) {
            $row = $stock->get((int) $item->product_id);
            $available = (int) ($row['available_qty'] ?? 0);
            $requested = (int) $item->quantity;

            return [
                'product_id' => (int) $item->product_id,
                'name' => $item->name_snapshot ?: ('منتج #'.$item->product_id),
                'requested_qty' => $requested,
                'available_qty' => $available,
                'fulfillable_qty' => min($requested, $available),
                'is_available' => $available >= $requested && $requested > 0,
                'points_per_unit' => (float) $item->points_per_unit,
            ];
        })->values()->all();
    }

    /**
     * Supplier edits order lines before fulfillment (placed / confirmed).
     *
     * @param  array<int, array{product_id:int, quantity:int}>  $lines
     */
    public function updateItems(Order $order, User $actor, array $lines): Order
    {
        $this->assertSupplier($order, $actor);
        $this->assertStatus(
            $order,
            [OrderStatus::PLACED, OrderStatus::CONFIRMED],
            'لا يمكن تعديل الأصناف بعد الشحن أو التسليم',
        );

        $prepared = $this->prepareLines($lines);

        if (empty($prepared['items'])) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل');
        }

        return DB::transaction(function () use ($order, $prepared) {
            $order->items()->delete();

            foreach ($prepared['items'] as $row) {
                OrderItem::create(['order_id' => $order->id, ...$row]);
            }

            $order->update([
                'total_quantity' => $prepared['total_quantity'],
                'total_points' => $prepared['total_points'],
            ]);

            return $order->fresh(['items', 'requester', 'supplier']);
        });
    }

    /**
     * Trim quantities to what the retail trader currently has in stock.
     * Removes lines with zero available stock.
     */
    public function applyAvailableStock(Order $order, User $actor): Order
    {
        $this->assertSupplier($order, $actor);

        if (! $order->isPlumberChannel()) {
            throw new \DomainException('تطبيق المخزون متاح لطلبات السباكين فقط');
        }

        $this->assertStatus(
            $order,
            [OrderStatus::PLACED, OrderStatus::CONFIRMED],
            'لا يمكن تعديل الأصناف بعد الشحن أو التسليم',
        );

        $availability = collect($this->stockAvailability($order));
        $lines = $availability
            ->filter(fn (array $row) => $row['fulfillable_qty'] > 0)
            ->map(fn (array $row) => [
                'product_id' => $row['product_id'],
                'quantity' => $row['fulfillable_qty'],
            ])
            ->values()
            ->all();

        if ($lines === []) {
            throw new \DomainException('لا يوجد أي صنف متوفر في مخزونك من هذا الطلب — عدّل الأصناف أو ارفض الطلب');
        }

        $order = $this->updateItems($order, $actor, $lines);

        $skipped = $availability->filter(fn (array $row) => $row['fulfillable_qty'] < $row['requested_qty'])->count();

        if ($skipped > 0) {
            $note = trim(($order->supplier_note ? $order->supplier_note."\n" : '')."تم تقليص {$skipped} صنف حسب المتوفر في المخزون.");
            $order->update(['supplier_note' => $note]);
        }

        return $order->fresh(['items', 'requester', 'supplier']);
    }

    /**
     * Retail trader executes a plumber order: stock check → distribute (invoice) → delivered.
     * Only products currently in the trader's stock can be fulfilled.
     */
    public function fulfillAsInvoice(Order $order, User $actor, ?string $note = null): Order
    {
        $this->assertSupplier($order, $actor);

        if (! $order->isPlumberChannel()) {
            throw new \DomainException('تحويل الطلب لفاتورة متاح لطلبات السباكين فقط');
        }

        $this->assertStatus(
            $order,
            [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPING],
            'لا يمكن تنفيذ هذا الطلب في حالته الحالية',
        );

        $order->loadMissing(['items', 'requester', 'supplier']);

        if ($order->items->isEmpty()) {
            throw new \DomainException('الطلب بلا أصناف — عدّل الأصناف أولاً');
        }

        $availability = $this->stockAvailability($order);
        $shortages = collect($availability)->filter(fn (array $row) => ! $row['is_available'])->values();

        if ($shortages->isNotEmpty()) {
            $list = $shortages->map(function (array $row) {
                return "«{$row['name']}»: مطلوب {$row['requested_qty']} — متوفر {$row['available_qty']}";
            })->implode(' | ');

            throw new \DomainException(
                "لا يمكن التنفيذ — أصناف غير متوفرة بالكامل في مخزونك: {$list}. "
                .'عدّل الكميات أو اضغط «تطبيق المتوفر فقط» ثم نفّذ.'
            );
        }

        return DB::transaction(function () use ($order, $actor, $note) {
            if ($order->status === OrderStatus::PLACED) {
                $order->update([
                    'status' => OrderStatus::CONFIRMED,
                    'confirmed_at' => now(),
                    'confirmed_by' => $actor->id,
                ]);
            }

            if (! $order->shipped_at) {
                $order->update([
                    'status' => OrderStatus::SHIPPING,
                    'shipped_at' => now(),
                    'shipped_by' => $actor->id,
                ]);
            }

            if ($note) {
                $order->update(['supplier_note' => $note]);
            }

            $order = $order->fresh(['items', 'requester', 'supplier']);

            $lines = $order->items->map(fn (OrderItem $item) => [
                'product_id' => (int) $item->product_id,
                'quantity' => (int) $item->quantity,
                'points_per_unit' => (float) $item->points_per_unit,
            ])->all();

            $reference = $this->fulfilRetailToPlumber($order, $lines);

            $order->update([
                'status' => OrderStatus::DELIVERED,
                'delivered_at' => now(),
                'delivered_by' => $actor->id,
                'delivered_reference' => $reference['reference'] ?? null,
            ]);

            $points = (int) $order->total_points;
            $pointsNote = $points > 0 ? " وتم إضافة {$points} نقطة لمحفظتك." : '.';

            $this->notifyRequester(
                $order,
                'تم تنفيذ طلبك ✓',
                "طلب رقم {$order->order_number} تم تحويله لفاتورة وتسليمه{$pointsNote}",
                'success',
            );

            return $order->fresh(['items', 'requester', 'supplier']);
        });
    }

    protected function assertSupplier(Order $order, User $actor): void
    {
        if (in_array($actor->role, ['super_admin', 'admin'], true)) {
            return;
        }

        if ((int) $actor->id !== (int) $order->supplier_id) {
            throw new \DomainException('يمكن للمورّد فقط تنفيذ هذه العملية');
        }
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

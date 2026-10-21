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
        $prepared = $this->prepareLines(
            $lines,
            enforceFactoryBoxes: in_array($channel, [
                OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE,
                OrderStatus::CHANNEL_WAREHOUSE_TO_WHOLESALE,
            ], true),
        );

        if (empty($prepared['items'])) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل للطلب');
        }

        if ($channel === OrderStatus::CHANNEL_RETAIL_TO_PLUMBER && $supplier) {
            $requested = [];
            foreach ($prepared['items'] as $row) {
                $requested[(int) $row['product_id']] = (int) $row['quantity'];
            }

            $this->inventory->allocateFromStock(
                $this->inventory->stockForRetailTrader($supplier),
                $requested,
            );
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

            if ($channel === OrderStatus::CHANNEL_RETAIL_TO_PLUMBER && $supplier && $requester->isPlumber()) {
                // Attach this shop to the plumber's multi-shop network (no-op if already linked).
                app(PlumberRetailLinkService::class)->link($supplier, $requester);
            }

            if ($channel === OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL && $supplier && $requester->isRetailTrader()) {
                // Attach this wholesaler to the retailer's multi-wholesaler network.
                app(RetailNetworkLinkService::class)->attach($supplier, $requester, $requester);
            }

            return $order;
        });

        $fresh = $order->fresh(['requester', 'supplier']);
        $this->notifyPlaced($fresh);
        $this->notifyCustomerWhatsApp($fresh);
        $this->safeWhatsApp(fn () => app(CustomerWhatsAppNotifier::class)->notifySupplierOrderArrived($fresh));

        return $order->load('items');
    }

    /** Supplier accepts the order. Stock is enforced on ship/deliver, not confirm. */
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
        $this->safeWhatsApp(fn () => app(CustomerWhatsAppNotifier::class)->notifyOrderStatus(
            $order->fresh(['requester']),
            'تم تأكيد طلبك ✓ ويجري تجهيزه.',
        ));

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

        $order->loadMissing(['items', 'supplier', 'requester']);

        // Receive/deliver deducts supplier stock — block ship when it cannot be fulfilled.
        $this->assertSupplierCanFulfill($order);

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
        $this->safeWhatsApp(fn () => app(CustomerWhatsAppNotifier::class)->notifyOrderStatus(
            $order->fresh(['requester']),
            "طلبك في الطريق 🚚{$tracking}",
        ));

        return $order;
    }

    /**
     * Confirm receipt — ONLY the buyer (requester / الطرف المستلم).
     * Stock moves to the buyer via the distribution pipeline (unless already transferred on fulfill).
     *
     * @throws \DomainException
     */
    public function deliver(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->assertBuyer($locked, $actor);

            $this->assertStatus(
                $locked,
                [OrderStatus::SHIPPING, OrderStatus::CONFIRMED],
                'لا يمكن تأكيد الاستلام قبل تأكيد أو شحن الطلب',
            );

            $locked->loadMissing('items', 'requester', 'supplier');

            // Plumber fulfill may already move stock while leaving status=shipping.
            $alreadyTransferred = filled($locked->delivered_reference)
                || filled($locked->delivered_invoice_id);

            if ($alreadyTransferred) {
                $reference = [
                    'invoice_id' => $locked->delivered_invoice_id,
                    'reference' => $locked->delivered_reference,
                ];
            } else {
                $reference = $this->fulfilStock($locked, $actor);

                // Plumber channel credits points via tier-3 distribution — skip double credit.
                if (! $locked->isPlumberChannel()) {
                    $this->creditRequesterPoints($locked, $actor, $reference['invoice_id'] ?? null);
                }
            }

            $locked->update([
                'status' => OrderStatus::DELIVERED,
                'delivered_at' => now(),
                'delivered_by' => $actor->id,
                'delivered_invoice_id' => $reference['invoice_id'] ?? $locked->delivered_invoice_id,
                'delivered_reference' => $reference['reference'] ?? $locked->delivered_reference,
            ]);

            $points = (int) $locked->total_points;
            $pointsNote = $points > 0
                ? ($locked->isPlumberChannel()
                    ? " وتم إضافة {$points} نقطة لمحفظة السباك."
                    : " وتم إضافة {$points} نقطة لمحفظتك.")
                : '.';

            $this->notifyRequester(
                $locked,
                'تم استلام طلبك ✓',
                "طلب رقم {$locked->order_number} تم تأكيد استلامه{$pointsNote}",
                'success',
            );

            $this->notifySupplierAndAdmins(
                $locked,
                'تم استلام الطلب ✓',
                "طلب رقم {$locked->order_number} تم استلامه وتأكيده من {$locked->requester?->name}.",
                'success',
            );

            $this->safeWhatsApp(fn () => app(CustomerWhatsAppNotifier::class)->notifyOrderStatus(
                $locked->fresh(['requester']),
                'تم استلام طلبك ✓'.$pointsNote,
            ));

            return $locked->fresh(['items', 'requester', 'supplier']);
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

    /**
     * Buyer cancels their order while open.
     * Shipping may be cancelled when supplier stock can no longer fulfill receive
     * (avoids stuck orders that cannot receive and previously could not cancel).
     */
    public function cancel(Order $order, User $actor, ?string $reason = null): Order
    {
        $allowed = [OrderStatus::PLACED, OrderStatus::CONFIRMED];

        if ($order->status === OrderStatus::SHIPPING) {
            if ($this->supplierCanFulfill($order)) {
                throw new \DomainException('لا يمكن إلغاء الطلب بعد شحنه طالما يمكن استلامه — تواصل مع المورّد');
            }
            $allowed[] = OrderStatus::SHIPPING;
        }

        $this->assertStatus($order, $allowed, 'لا يمكن إلغاء الطلب في حالته الحالية');

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

        if ($order->supplier) {
            $this->safeWhatsApp(fn () => app(CustomerWhatsAppNotifier::class)->notifySupplierStatus(
                $order->fresh(['requester', 'supplier']),
                'أُلغي الطلب من قبل المشتري'.($reason ? " — السبب: {$reason}" : ''),
            ));
        }

        return $order;
    }

    /**
     * Supplier rejects the order (also undeliverable shipping when stock is gone).
     */
    public function reject(Order $order, User $actor, ?string $reason = null): Order
    {
        $allowed = [OrderStatus::PLACED, OrderStatus::CONFIRMED];

        if ($order->status === OrderStatus::SHIPPING && ! $this->supplierCanFulfill($order)) {
            $allowed[] = OrderStatus::SHIPPING;
        }

        $this->assertStatus($order, $allowed, 'لا يمكن رفض هذا الطلب في حالته الحالية');

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
        $this->safeWhatsApp(fn () => app(CustomerWhatsAppNotifier::class)->notifyOrderStatus(
            $order->fresh(['requester']),
            'تم رفض الطلب'.($reason ? " — السبب: {$reason}" : ''),
        ));

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

        if ($channel === OrderStatus::CHANNEL_WAREHOUSE_TO_WHOLESALE) {
            if (! $requester->isWholesaleDistributor()) {
                throw new \DomainException('الطلب من المخزن متاح لموزّعي الجملة فقط');
            }

            if ($requester->isCentralWarehouse()) {
                throw new \DomainException('المخزن المركزي يطلب من المصنع مباشرة — اختر المصدر: factory');
            }

            $warehouseId = (int) ($meta['warehouse_id'] ?? $meta['supplier_id'] ?? 0);
            $warehouse = $warehouseId > 0 ? User::query()->find($warehouseId) : null;

            if (! $warehouse || ! $warehouse->isCentralWarehouse()) {
                throw new \DomainException('اختر مخزناً مركزياً صالحاً (warehouse_id)');
            }

            if (! $warehouse->is_active || ! $warehouse->is_approved) {
                throw new \DomainException('هذا المخزن غير مفعّل حالياً');
            }

            return $warehouse;
        }

        if ($channel === OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL) {
            if (! $requester->isRetailTrader()) {
                throw new \DomainException('الطلب من الجملة متاح لتجّار القطاعي فقط');
            }

            $supplierId = (int) ($meta['wholesale_distributor_id'] ?? $meta['supplier_id'] ?? 0);
            $supplier = null;

            if ($supplierId > 0) {
                $supplier = User::find($supplierId);
            }

            if ((! $supplier || ! $supplier->isWholesaleDistributor()) && $requester->parent_distributor_id) {
                $supplier = User::find($requester->parent_distributor_id);
            }

            if ((! $supplier || ! $supplier->isWholesaleDistributor()) && $requester->relationLoaded('linkedWholesalers')) {
                $supplier = $requester->linkedWholesalers->first();
            }

            if (! $supplier || ! $supplier->isWholesaleDistributor()) {
                $supplier = $requester->linkedWholesalers()->first();
            }

            if (! $supplier || ! $supplier->isWholesaleDistributor()) {
                throw new \DomainException('اختر موزّع الجملة (supplier_id) لإرسال الطلب إليه');
            }

            if (! $supplier->is_active || ! $supplier->is_approved) {
                throw new \DomainException('هذا الموزّع غير مفعّل حالياً');
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

            // Plumber may order from any retail shop; first order to a shop adds it to their network.
            // (Multi-shop: no exclusive lock to a single retail trader.)

            return $supplier;
        }

        throw new \DomainException('نوع الطلب غير صالح');
    }

    /**
     * Cart quote for mobile — totals only; never expose per-product points rates.
     *
     * @param  array<int, array{product_id:int, quantity?:int, boxes?:int}>  $lines
     * @return array{
     *   total_quantity:int,
     *   total_points:int,
     *   items:list<array<string, mixed>>
     * }
     */
    public function quote(array $lines, bool $enforceFactoryBoxes = true): array
    {
        $prepared = $this->prepareLines($lines, enforceFactoryBoxes: $enforceFactoryBoxes);

        $items = [];
        foreach ($prepared['items'] as $row) {
            $product = Product::query()->find($row['product_id']);
            $piecesPerBox = (int) ($product?->pieces_per_box ?? 0);
            $qty = (int) $row['quantity'];

            $items[] = [
                'product_id' => (int) $row['product_id'],
                'name' => $row['name_snapshot'],
                'order_unit' => $piecesPerBox > 0 ? 'box' : 'piece',
                'pieces_per_box' => $piecesPerBox > 0 ? $piecesPerBox : null,
                'boxes' => $piecesPerBox > 0 ? (int) ($qty / $piecesPerBox) : null,
                'quantity' => $qty,
                // Apps must not show product points — only order total_points.
                'points_per_unit' => null,
                'line_points' => null,
            ];
        }

        return [
            'total_quantity' => (int) $prepared['total_quantity'],
            'total_points' => (int) $prepared['total_points'],
            'items' => $items,
        ];
    }

    /**
     * Normalize cart lines into piece quantities.
     *
     * Boxed MG products (pieces_per_box > 0): prefer `boxes` and convert to pieces.
     * If only `quantity` is sent for a boxed product, it must be a multiple of pieces_per_box.
     *
     * @param  array<int, array{product_id:int, quantity?:int, boxes?:int}>  $lines
     * @return array{items: list<array<string, mixed>>, total_quantity: int, total_points: int}
     */
    protected function prepareLines(array $lines, bool $enforceFactoryBoxes = false): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $product = Product::query()->find($productId);
            if (! $product) {
                continue;
            }

            $piecesPerBox = (int) ($product->pieces_per_box ?? 0);
            $qty = $this->resolveLinePieceQuantity($line, $piecesPerBox, $enforceFactoryBoxes);

            if ($qty <= 0) {
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

            // Non-MG / zero-point catalog items stay orderable with 0 points.
            $ppu = $product->effectivePointsPerUnit();
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
     * @param  array{product_id?:int, quantity?:int, boxes?:int}  $line
     */
    protected function resolveLinePieceQuantity(array $line, int $piecesPerBox, bool $enforceFactoryBoxes): int
    {
        $hasBoxes = array_key_exists('boxes', $line) && $line['boxes'] !== null && $line['boxes'] !== '';
        $boxes = $hasBoxes ? (int) $line['boxes'] : 0;
        $rawQty = (int) ($line['quantity'] ?? 0);

        if ($piecesPerBox > 0) {
            if ($boxes > 0) {
                return $boxes * $piecesPerBox;
            }

            if ($rawQty <= 0) {
                return 0;
            }

            // Already expressed as pieces in a valid box multiple.
            if ($rawQty % $piecesPerBox === 0) {
                return $rawQty;
            }

            if ($enforceFactoryBoxes) {
                throw new \DomainException(
                    "المنتجات ذات الصندوق تُطلب بالصندوق فقط (كل صندوق = {$piecesPerBox} قطعة). أرسل عدد الصناديق أو كمية من مضاعفات {$piecesPerBox}."
                );
            }

            // Outside factory channel: treat as piece quantity (retail/plumber flows).
            return $rawQty;
        }

        // Piece-only products.
        if ($boxes > 0 && $rawQty <= 0) {
            return $boxes;
        }

        return $rawQty;
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

        if ($order->isWarehouseChannel()) {
            return $this->fulfilWarehouseToWholesale($order, $lines, $actor);
        }

        // wholesale → retail
        $wholesaler = $order->supplier;
        $retailTrader = $order->requester;

        if (! $wholesaler || ! $retailTrader) {
            throw new \DomainException('تعذّر تحديد أطراف الطلب');
        }

        $links = app(RetailNetworkLinkService::class);
        if (! $links->isLinked($wholesaler, $retailTrader)) {
            // Order was already accepted into the network flow — attach on deliver.
            $links->attach($wholesaler, $retailTrader, $actor);
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
     * Warehouse (central) supplies another wholesaler: burn warehouse slots + issue incoming stock to buyer.
     *
     * @param  array<int, array{product_id:int, quantity:int, points_per_unit?:float}>  $lines
     * @return array{invoice_id?:?int, reference?:?array<string, mixed>}
     */
    protected function fulfilWarehouseToWholesale(Order $order, array $lines, User $actor): array
    {
        $warehouse = $order->supplier;
        $buyer = $order->requester;

        if (! $warehouse?->isCentralWarehouse() || ! $buyer?->isWholesaleDistributor()) {
            throw new \DomainException('تعذّر تسليم طلب المخزن: الأطراف غير صالحة');
        }

        $this->assertWholesalerOrderFullyStocked($order);

        $stock = $this->inventory->stockForWholesaler($warehouse);
        $requested = [];
        foreach ($lines as $line) {
            $requested[$line['product_id']] = ($requested[$line['product_id']] ?? 0) + (int) $line['quantity'];
        }
        $groups = $this->inventory->allocateFromStock($stock, $requested);

        foreach ($groups as $group) {
            $invoice = Invoice::query()->findOrFail($group['invoice_id']);

            $distribution = $this->distributions->createDistribution(
                invoice: $invoice,
                fromUser: $warehouse,
                toUser: $buyer,
                tier: 2,
                items: $group['items'],
                parentId: $group['parent_distribution_id'],
                skipCallerCheck: true,
            );

            $this->distributions->confirmDistribution($distribution->fresh(['items']));
        }

        // Buyer receives MG wholesale stock (same shape as factory incoming).
        $incoming = $this->wholesaleInvoices->issueFromCart(
            wholesaler: $buyer,
            lines: $lines,
            issuedBy: $actor,
        );

        return [
            'invoice_id' => $incoming->id,
            'reference' => ['invoice_number' => $incoming->number, 'source' => 'warehouse'],
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

        $this->assertPlumberOrderFullyStocked($order);

        $stock = $this->inventory->stockForRetailTrader($retailTrader);

        $requested = [];
        foreach ($lines as $line) {
            $requested[$line['product_id']] = ($requested[$line['product_id']] ?? 0) + (int) $line['quantity'];
        }

        $groups = $this->inventory->allocateFromStock($stock, $requested);
        $distributionIds = [];
        $reputation = ['new_badges' => [], 'score_delta' => 0, 'level_changed' => false];

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

            $rep = $this->distributions->confirmDistribution($distribution->fresh(['items']));
            $distributionIds[] = $distribution->id;
            $reputation['score_delta'] += (int) ($rep['score_delta'] ?? 0);
            $reputation['level_changed'] = $reputation['level_changed'] || ! empty($rep['level_changed']);
            $reputation['new_badges'] = array_merge($reputation['new_badges'], $rep['new_badges'] ?? []);
        }

        return [
            'invoice_id' => null,
            'reference' => ['distribution_ids' => $distributionIds],
            'reputation' => $reputation,
        ];
    }

    /**
     * Whether the supplier currently has enough stock for every line (receive/deliver safe).
     * Factory channel always returns true (stock is issued from factory inventory).
     */
    public function supplierCanFulfill(Order $order): bool
    {
        try {
            $this->assertSupplierCanFulfill($order);

            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    /**
     * @throws \DomainException
     */
    public function assertSupplierCanFulfill(Order $order): void
    {
        $order->loadMissing(['items', 'supplier']);

        if ($order->isFactoryChannel()) {
            return;
        }

        if ($order->items->isEmpty()) {
            throw new \DomainException('الطلب بلا أصناف');
        }

        if ($order->isPlumberChannel()) {
            $this->assertPlumberOrderFullyStocked($order);

            return;
        }

        // wholesale → retail
        $this->assertWholesalerOrderFullyStocked($order);
    }

    /**
     * Ensure the retail trader has enough network stock for every plumber-order line.
     *
     * @throws \DomainException
     */
    protected function assertPlumberOrderFullyStocked(Order $order): void
    {
        $order->loadMissing(['items', 'supplier']);

        if (! $order->isPlumberChannel() || ! $order->supplier) {
            return;
        }

        if ($order->items->isEmpty()) {
            throw new \DomainException('الطلب بلا أصناف');
        }

        $stock = $this->inventory->stockForRetailTrader($order->supplier);

        if ($stock->isEmpty()) {
            throw new \DomainException(
                'مخزون التاجر القطاعي فارغ حالياً — يجب توريد بضاعة من موزّع الجملة أولاً (POS أو استلام طلب جملة) قبل شحن/تسليم طلب السباك'
            );
        }

        $availability = $this->stockAvailability($order);
        $shortages = collect($availability)->filter(fn (array $row) => ! $row['is_available'])->values();

        if ($shortages->isEmpty()) {
            return;
        }

        $list = $shortages->map(function (array $row) {
            return "«{$row['name']}»: مطلوب {$row['requested_qty']} — متوفر {$row['available_qty']}";
        })->implode(' | ');

        throw new \DomainException(
            "مخزون التاجر القطاعي غير كافٍ لتسليم الطلب: {$list}. "
            .'عدّل الكميات من لوحة التاجر أو طبّق المتوفر ثم نفّذ كفاتورة.'
        );
    }

    /**
     * Ensure the wholesaler has enough stock for a wholesale→retail order.
     *
     * @throws \DomainException
     */
    protected function assertWholesalerOrderFullyStocked(Order $order): void
    {
        if (! in_array($order->channel, [
            OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL,
            OrderStatus::CHANNEL_WAREHOUSE_TO_WHOLESALE,
        ], true) || ! $order->supplier) {
            return;
        }

        $stock = $this->inventory->stockForWholesaler($order->supplier)->keyBy('product_id');
        $emptyLabel = $order->isWarehouseChannel()
            ? 'مخزون المخزن المركزي فارغ حالياً — تعذّر تأكيد/شحن الطلب'
            : 'مخزون موزّع الجملة فارغ حالياً — تعذّر تأكيد/شحن الطلب';

        if ($stock->isEmpty()) {
            throw new \DomainException($emptyLabel);
        }

        $shortages = [];
        foreach ($order->items as $item) {
            $row = $stock->get((int) $item->product_id);
            $available = (int) ($row['available_qty'] ?? 0);
            $requested = (int) $item->quantity;
            if ($available < $requested) {
                $name = $item->name_snapshot ?: ($row['name'] ?? 'منتج');
                $shortages[] = "«{$name}»: مطلوب {$requested} — متوفر {$available}";
            }
        }

        if ($shortages === []) {
            return;
        }

        throw new \DomainException(
            'مخزون موزّع الجملة غير كافٍ: '.implode(' | ', $shortages)
        );
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
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus(
                $locked,
                [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPING],
                'لا يمكن تنفيذ هذا الطلب في حالته الحالية',
            );

            $locked->loadMissing(['items', 'requester', 'supplier']);

            if ($locked->status === OrderStatus::PLACED) {
                $locked->update([
                    'status' => OrderStatus::CONFIRMED,
                    'confirmed_at' => now(),
                    'confirmed_by' => $actor->id,
                ]);
            }

            if (! $locked->shipped_at) {
                $locked->update([
                    'status' => OrderStatus::SHIPPING,
                    'shipped_at' => now(),
                    'shipped_by' => $actor->id,
                ]);
            }

            if ($note) {
                $locked->update(['supplier_note' => $note]);
            }

            $locked = $locked->fresh(['items', 'requester', 'supplier']);

            $lines = $locked->items->map(fn (OrderItem $item) => [
                'product_id' => (int) $item->product_id,
                'quantity' => (int) $item->quantity,
                'points_per_unit' => (float) $item->points_per_unit,
            ])->all();

            $reference = $this->fulfilRetailToPlumber($locked, $lines);

            // Stock/points moved — wait for plumber (buyer) to confirm receipt.
            $locked->update([
                'status' => OrderStatus::SHIPPING,
                'shipped_at' => $locked->shipped_at ?? now(),
                'shipped_by' => $locked->shipped_by ?? $actor->id,
                'delivered_reference' => $reference['reference'] ?? null,
                'delivered_invoice_id' => null,
                'delivered_at' => null,
                'delivered_by' => null,
            ]);

            $order = $locked;

            $points = (int) $order->total_points;
            $pointsNote = $points > 0 ? " ({$points} نقطة بانتظار تأكيد استلامك)" : '';

            $this->notifyRequester(
                $order,
                'طلبك جاهز للاستلام ✓',
                "طلب رقم {$order->order_number} تم تجهيزه من التاجر{$pointsNote}. أكّد الاستلام لإتمام الطلب.",
                'info',
            );

            $fresh = $order->fresh(['items', 'requester', 'supplier']);
            $fresh->setAttribute('_reputation_celebration', $reference['reputation'] ?? [
                'new_badges' => [],
                'score_delta' => 0,
                'level_changed' => false,
            ]);

            return $fresh;
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

    /** Receipt is always the counterparty (requester), never the supplier. */
    protected function assertBuyer(Order $order, User $actor): void
    {
        if ((int) $actor->id !== (int) $order->requester_id) {
            throw new \DomainException('يمكن للمستلم (صاحب الطلب) فقط تأكيد الاستلام');
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
        $meta = $this->orderNotificationMeta($order);

        if ($order->isFactoryChannel()) {
            AdminNotificationService::sendToRole('super_admin', $title, $body, 'info', null, null, $meta);
            AdminNotificationService::sendToRole('admin', $title, $body, 'info', null, null, $meta);

            return;
        }

        if ($order->supplier) {
            AdminNotificationService::send($order->supplier, $title, $body, 'info', null, null, $meta);
        }
    }

    protected function notifyCustomerWhatsApp(Order $order): void
    {
        $this->safeWhatsApp(fn () => app(CustomerWhatsAppNotifier::class)->notifyOrderPlaced($order));
    }

    protected function safeWhatsApp(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[WhatsApp] order notify skipped', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function notifyRequester(Order $order, string $title, string $body, string $status): void
    {
        if ($order->requester) {
            AdminNotificationService::send(
                $order->requester,
                $title,
                $body,
                $status,
                null,
                null,
                $this->orderNotificationMeta($order),
            );
        }
    }

    protected function notifySupplierAndAdmins(Order $order, string $title, string $body, string $status): void
    {
        $meta = $this->orderNotificationMeta($order);

        if ($order->isFactoryChannel()) {
            AdminNotificationService::sendToRole('super_admin', $title, $body, $status, null, null, $meta);
            AdminNotificationService::sendToRole('admin', $title, $body, $status, null, null, $meta);

            return;
        }

        if ($order->supplier) {
            AdminNotificationService::send($order->supplier, $title, $body, $status, null, null, $meta);
        }
    }

    /** @return array<string, mixed> */
    protected function orderNotificationMeta(Order $order): array
    {
        return [
            'entity_type' => 'order',
            'order_id' => (int) $order->id,
            'order_number' => $order->order_number,
            'channel' => $order->channel,
            'order_status' => $order->status,
        ];
    }
}

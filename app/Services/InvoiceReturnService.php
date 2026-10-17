<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceReturn;
use App\Models\InvoiceReturnItem;
use App\Models\User;
use App\Support\InvoiceReturnStatus;
use Illuminate\Support\Facades\DB;

class InvoiceReturnService
{
    /**
     * Request a return (pending). Stock/points apply only after approve.
     *
     * @param  array<int, array{invoice_item_id:int, quantity:int}>  $lines
     */
    public function returnDistribution(
        InvoiceDistribution $distribution,
        array $lines,
        User $actor,
        ?string $note = null,
        ?Invoice $documentInvoice = null,
    ): InvoiceReturn {
        $distribution->loadMissing(['items.invoiceItem.product', 'invoice', 'fromUser', 'toUser']);

        if (! in_array($distribution->status, ['confirmed', 'points_awarded'], true)) {
            throw new \DomainException('لا يمكن عمل مرتجع إلا على توزيع مؤكّد أو تم صرف نقاطه');
        }

        if (! in_array((int) $distribution->tier, [2, 3], true)) {
            throw new \DomainException('المرتجعات متاحة فقط لتوزيعات الجملة→قطاعي أو القطاعي→سباك — مرتجع المصنع غير مدعوم من هذا المسار');
        }

        $this->assertNotWarehouseToWholesaleSupply($distribution);
        $this->assertActorCanReturn($distribution, $actor);

        $prepared = $this->prepareLines($distribution, $lines);

        if ($prepared === []) {
            throw new \DomainException('أضف كمية مرتجعة واحدة على الأقل لصنف صحيح');
        }

        $totalPoints = (int) collect($prepared)->sum('points_value');
        $this->assertPointsCanBeReturned($distribution, $totalPoints);

        $documentInvoice ??= $this->resolveDocumentInvoice($distribution);

        return DB::transaction(function () use ($distribution, $prepared, $actor, $note, $documentInvoice, $totalPoints) {
            $totalQty = 0;

            foreach ($prepared as $row) {
                $distItem = InvoiceDistributionItem::query()
                    ->whereKey($row['distribution_item_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $returnable = $this->returnableQtyForItem($distribution, $distItem);

                if ($row['quantity'] > $returnable) {
                    $name = localized_name($distItem->invoiceItem?->product, 'name', 'منتج');
                    throw new \DomainException(
                        "كمية المرتجع ({$row['quantity']}) للمنتج «{$name}» تتجاوز المتاح للإرجاع ({$returnable})"
                    );
                }

                $totalQty += $row['quantity'];
            }

            $return = InvoiceReturn::create([
                'return_number' => sprintf('RET-%s-%s', now()->format('YmdHis'), strtoupper(substr(uniqid(), -5))),
                'invoice_id' => $documentInvoice->id,
                'distribution_id' => $distribution->id,
                'from_user_id' => $distribution->to_user_id,
                'to_user_id' => $distribution->from_user_id,
                'tier' => $distribution->tier,
                'status' => InvoiceReturnStatus::PENDING,
                'total_quantity' => $totalQty,
                'total_points' => $totalPoints,
                'note' => $note,
                'created_by' => $actor->id,
                'confirmed_at' => null,
            ]);

            $return->update([
                'return_number' => sprintf('RET-%s-%06d', now()->format('Y'), $return->id),
            ]);

            foreach ($prepared as $row) {
                InvoiceReturnItem::create([
                    'invoice_return_id' => $return->id,
                    'invoice_item_id' => $row['invoice_item_id'],
                    'distribution_item_id' => $row['distribution_item_id'],
                    'product_id' => $row['product_id'],
                    'quantity' => $row['quantity'],
                    'points_value' => $row['points_value'],
                ]);
            }

            return $return->load(['items.product.translations', 'fromUser', 'toUser']);
        });
    }

    /** Approve pending return — applies stock + wallet side-effects. */
    public function approve(InvoiceReturn $return, User $actor, ?string $note = null): InvoiceReturn
    {
        $return->loadMissing(['items', 'distribution.items.invoiceItem.product', 'invoice']);

        if (! InvoiceReturnStatus::isPending($return->status)) {
            throw new \DomainException('لا يمكن قبول مرتجع إلا وهو بانتظار الموافقة');
        }

        $this->assertActorCanDecide($return, $actor);

        $distribution = $return->distribution;
        if (! $distribution) {
            throw new \DomainException('تعذّر العثور على توزيع المرتجع');
        }

        $distribution->loadMissing(['items.invoiceItem.product', 'fromUser', 'toUser']);
        $this->assertNotWarehouseToWholesaleSupply($distribution);

        return DB::transaction(function () use ($return, $actor, $note, $distribution) {
            $lockedReturn = InvoiceReturn::query()
                ->whereKey($return->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! InvoiceReturnStatus::isPending($lockedReturn->status)) {
                throw new \DomainException('لا يمكن قبول مرتجع إلا وهو بانتظار الموافقة');
            }

            $lockedReturn->loadMissing(['items', 'invoice']);

            $confirmedPoints = 0;

            foreach ($lockedReturn->items as $item) {
                $distItem = InvoiceDistributionItem::query()
                    ->whereKey($item->distribution_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $returnable = $this->returnableQtyForItem($distribution, $distItem, ignoreReturnId: (int) $lockedReturn->id);

                if ((int) $item->quantity > $returnable) {
                    $name = localized_name($distItem->invoiceItem?->product, 'name', 'منتج');
                    throw new \DomainException(
                        "كمية المرتجع ({$item->quantity}) للمنتج «{$name}» تتجاوز المتاح للإرجاع ({$returnable})"
                    );
                }

                $distItem->returned_quantity = (int) $distItem->returned_quantity + (int) $item->quantity;
                $distItem->save();
                $confirmedPoints += (int) $item->points_value;
            }

            if ((int) $lockedReturn->tier === 3 && $confirmedPoints > 0) {
                $this->assertPointsCanBeReturned($distribution, $confirmedPoints);
                $this->transferPoints(
                    from: User::findOrFail($lockedReturn->from_user_id),
                    to: User::findOrFail($lockedReturn->to_user_id),
                    points: $confirmedPoints,
                    return: $lockedReturn,
                    actor: $actor,
                );
            }

            $lockedReturn->update([
                'status' => InvoiceReturnStatus::APPROVED,
                'confirmed_at' => now(),
                'note' => $note ?: $lockedReturn->note,
            ]);

            if ($lockedReturn->invoice) {
                $this->syncOutgoingInvoiceTotals($lockedReturn->invoice->fresh(['sourceDistribution.items', 'returns']));
            }

            return $lockedReturn->fresh(['items.product.translations', 'fromUser', 'toUser']);
        });
    }

    /** Reject pending return — no stock/points changes. */
    public function reject(InvoiceReturn $return, User $actor, ?string $reason = null): InvoiceReturn
    {
        if (! InvoiceReturnStatus::isPending($return->status)) {
            throw new \DomainException('لا يمكن رفض مرتجع إلا وهو بانتظار الموافقة');
        }

        $this->assertActorCanDecide($return, $actor);

        $return->update([
            'status' => InvoiceReturnStatus::REJECTED,
            'note' => $reason
                ? trim(($return->note ? $return->note."\n" : '').'رفض: '.$reason)
                : $return->note,
        ]);

        return $return->fresh(['items.product.translations', 'fromUser', 'toUser']);
    }

    public function assertActorCanDecide(InvoiceReturn $return, User $actor): void
    {
        if (in_array($actor->role, ['super_admin', 'admin'], true)) {
            return;
        }

        // Central warehouse oversees network returns.
        if ($actor->isCentralWarehouse()) {
            return;
        }

        if ((int) $return->to_user_id !== (int) $actor->id) {
            throw new \DomainException('قبول/رفض المرتجع متاح للطرف المستلم (البائع الأصلي) أو المشرف فقط');
        }
    }

    /**
     * @param  array<int, array{invoice_item_id:int, quantity:int}>  $lines
     */
    public function returnOutgoingInvoice(Invoice $invoice, array $lines, User $actor, ?string $note = null): InvoiceReturn
    {
        if (! $invoice->isWholesalePos() || $invoice->invoice_flow !== 'outgoing') {
            throw new \DomainException('مرتجع الجملة→قطاعي متاح على فواتير الصادر لنقاط البيع فقط');
        }

        $distribution = $invoice->sourceDistribution
            ?? InvoiceDistribution::query()->whereKey($invoice->source_distribution_id)->first();

        if (! $distribution) {
            throw new \DomainException('تعذّر العثور على توزيع الفاتورة المرتبط — تأكد أن الفاتورة صادرة ومؤكّدة');
        }

        if ((int) $distribution->tier !== 2) {
            throw new \DomainException('هذه الفاتورة ليست توزيع جملة→قطاعي');
        }

        return $this->returnDistribution($distribution, $lines, $actor, $note, $invoice);
    }

    /**
     * @param  array<int, array{invoice_item_id:int, quantity:int}>  $lines
     */
    public function returnPlumberDistribution(
        InvoiceDistribution $distribution,
        array $lines,
        User $actor,
        ?string $note = null,
    ): InvoiceReturn {
        if ((int) $distribution->tier !== 3) {
            throw new \DomainException('هذا المسار لمرتجع القطاعي→سباك فقط (tier 3)');
        }

        return $this->returnDistribution($distribution, $lines, $actor, $note);
    }

    /**
     * @return array<int, array{invoice_item_id:int, product_name:string, returnable:int, points_per_unit:float, sold:int, returned:int, has_points:bool}>
     */
    public function returnableLines(InvoiceDistribution $distribution): array
    {
        $distribution->loadMissing(['items.invoiceItem.product.translations']);

        $lines = [];

        foreach ($distribution->items as $distItem) {
            $returnable = $this->returnableQtyForItem($distribution, $distItem);

            if ($returnable <= 0) {
                continue;
            }

            $ppu = (float) ($distItem->invoiceItem?->points_per_unit ?? 0);

            $lines[] = [
                'invoice_item_id' => (int) $distItem->invoice_item_id,
                'product_id' => $distItem->invoiceItem?->product_id ? (int) $distItem->invoiceItem->product_id : null,
                'product_name' => localized_name($distItem->invoiceItem?->product, 'name', 'منتج'),
                'sold' => (int) $distItem->quantity,
                'returned' => (int) ($distItem->returned_quantity ?? 0),
                'returnable' => $returnable,
                'points_per_unit' => $ppu,
                'has_points' => $ppu > 0,
            ];
        }

        return $lines;
    }

    /**
     * @return array{
     *   sold_qty:int, returned_qty:int, net_qty:int,
     *   sold_points:int, returned_points:int, net_points:int,
     *   returns_count:int,
     *   can_return:bool,
     *   block_reason:?string
     * }
     */
    public function invoiceReturnSummary(Invoice $invoice): array
    {
        $invoice->loadMissing(['returns', 'sourceDistribution.items']);

        $settled = $invoice->returns->filter(
            fn (InvoiceReturn $r) => InvoiceReturnStatus::isSettled($r->status)
        );

        $returnedQty = (int) $settled->sum('total_quantity');
        $returnedPoints = (int) $settled->sum('total_points');
        $returnsCount = $settled->count();

        if ($invoice->isOutgoing() && $invoice->sourceDistribution) {
            $soldQty = (int) $invoice->sourceDistribution->items->sum('quantity');
            $soldPoints = (int) $invoice->sourceDistribution->items->sum('points_value');
        } else {
            $soldQty = (int) $invoice->items->sum('quantity');
            $soldPoints = (int) ($invoice->points_awarded ?: $invoice->items->sum('total_points'));
        }

        $netPoints = max(0, $soldPoints - $returnedPoints);
        $canReturn = true;
        $blockReason = null;
        $dist = $invoice->sourceDistribution;

        if ($dist && (int) $dist->tier === 3 && $netPoints > 0) {
            try {
                $this->assertPointsCanBeReturned($dist, $netPoints);
            } catch (\DomainException $e) {
                $canReturn = false;
                $blockReason = $e->getMessage();
            }
        }

        return [
            'sold_qty' => $soldQty,
            'returned_qty' => $returnedQty,
            'net_qty' => max(0, $soldQty - $returnedQty),
            'sold_points' => $soldPoints,
            'returned_points' => $returnedPoints,
            'net_points' => $netPoints,
            'returns_count' => $returnsCount,
            'can_return' => $canReturn,
            'block_reason' => $blockReason,
        ];
    }

    public function maxReturnablePoints(InvoiceDistribution $distribution): int
    {
        return (int) collect($this->returnableLines($distribution))->sum(
            fn (array $line) => (int) floor(($line['returnable'] ?? 0) * ($line['points_per_unit'] ?? 0))
        );
    }

    /**
     * @return array{can_return:bool, block_reason:?string}
     */
    public function previewReturnGate(InvoiceDistribution $distribution): array
    {
        try {
            if (! in_array($distribution->status, ['confirmed', 'points_awarded'], true)) {
                return [
                    'can_return' => false,
                    'block_reason' => 'لا يمكن عمل مرتجع إلا على توزيع مؤكّد أو تم صرف نقاطه',
                ];
            }

            $this->assertNotWarehouseToWholesaleSupply($distribution);

            if ($this->returnableLines($distribution) === []) {
                return [
                    'can_return' => false,
                    'block_reason' => 'لا توجد كميات متاحة للإرجاع على هذا التوزيع',
                ];
            }

            $this->assertPointsCanBeReturned($distribution, $this->maxReturnablePoints($distribution));

            return ['can_return' => true, 'block_reason' => null];
        } catch (\DomainException $e) {
            return ['can_return' => false, 'block_reason' => $e->getMessage()];
        }
    }

    /**
     * Warehouse→wholesale fulfilment burns warehouse slots AND remints a separate incoming
     * invoice for the buyer. Approving a tier-2 return only restores warehouse stock and
     * leaves the reminted buyer stock → network inflation. Block until a full reverse exists.
     */
    protected function assertNotWarehouseToWholesaleSupply(InvoiceDistribution $distribution): void
    {
        $distribution->loadMissing(['fromUser', 'toUser']);

        $from = $distribution->fromUser;
        $to = $distribution->toUser;

        if ($from?->isCentralWarehouse() && $to?->isWholesaleDistributor()) {
            throw new \DomainException(
                'مرتجع توريد المخزن→الجملة غير متاح حالياً — التسليم يُنشئ مخزوناً مستقلاً للمشتري؛ تواصل مع الإدارة لضبط المخزون'
            );
        }
    }

    protected function resolveDocumentInvoice(InvoiceDistribution $distribution): Invoice
    {
        if ((int) $distribution->tier === 2) {
            $outgoing = Invoice::query()
                ->where('source_distribution_id', $distribution->id)
                ->first();

            if ($outgoing) {
                return $outgoing;
            }
        }

        return $distribution->invoice ?? Invoice::query()->findOrFail($distribution->invoice_id);
    }

    protected function syncOutgoingInvoiceTotals(Invoice $invoice): void
    {
        if (! $invoice->isOutgoing()) {
            return;
        }

        $summary = $this->invoiceReturnSummary($invoice);

        $invoice->forceFill([
            'points_awarded' => $summary['net_points'],
        ])->save();
    }

    protected function returnableQtyForItem(
        InvoiceDistribution $distribution,
        InvoiceDistributionItem $distItem,
        ?int $ignoreReturnId = null,
    ): int {
        $returnable = (int) $distItem->quantity - (int) ($distItem->returned_quantity ?? 0);

        // Reserve quantities on pending returns for this distribution item.
        $pendingQty = (int) InvoiceReturnItem::query()
            ->where('distribution_item_id', $distItem->id)
            ->whereHas('invoiceReturn', function ($q) use ($distribution, $ignoreReturnId) {
                $q->where('distribution_id', $distribution->id)
                    ->where('status', InvoiceReturnStatus::PENDING);
                if ($ignoreReturnId) {
                    $q->whereKeyNot($ignoreReturnId);
                }
            })
            ->sum('quantity');

        $returnable -= $pendingQty;

        if ((int) $distribution->tier === 2) {
            $pushedDown = (int) InvoiceDistributionItem::query()
                ->where('invoice_item_id', $distItem->invoice_item_id)
                ->whereHas('distribution', fn ($q) => $q
                    ->where('tier', 3)
                    ->where('parent_distribution_id', $distribution->id)
                    ->whereIn('status', ['confirmed', 'points_awarded'])
                )
                ->sum(DB::raw('quantity - COALESCE(returned_quantity, 0)'));

            $returnable = max(0, $returnable - $pushedDown);
        }

        return max(0, $returnable);
    }

    /**
     * @return array<int, array{distribution_item_id:int, invoice_item_id:int, product_id:?int, quantity:int, points_value:int}>
     */
    protected function prepareLines(InvoiceDistribution $distribution, array $lines): array
    {
        $prepared = [];

        foreach ($lines as $line) {
            $invoiceItemId = (int) ($line['invoice_item_id'] ?? 0);
            $qty = (int) ($line['quantity'] ?? 0);

            if ($invoiceItemId <= 0 || $qty <= 0) {
                continue;
            }

            $distItem = $distribution->items->firstWhere('invoice_item_id', $invoiceItemId);

            if (! $distItem) {
                throw new \DomainException('أحد البنود غير موجود في هذا التوزيع — راجع invoice_item_id');
            }

            $ppu = (float) ($distItem->invoiceItem?->points_per_unit ?? 0);
            $points = (int) floor($qty * $ppu);

            $prepared[] = [
                'distribution_item_id' => (int) $distItem->id,
                'invoice_item_id' => $invoiceItemId,
                'product_id' => $distItem->invoiceItem?->product_id,
                'quantity' => $qty,
                'points_value' => $points,
            ];
        }

        return $prepared;
    }

    protected function assertActorCanReturn(InvoiceDistribution $distribution, User $actor): void
    {
        if (in_array($actor->role, ['super_admin', 'admin'], true)) {
            return;
        }

        $allowed = [
            (int) $distribution->from_user_id,
            (int) $distribution->to_user_id,
        ];

        if (! in_array((int) $actor->id, $allowed, true)) {
            throw new \DomainException('غير مصرّح لك بعمل مرتجع على هذا التوزيع — يجب أن تكون البائع أو المشتري أو مشرفاً');
        }
    }

    protected function assertPointsCanBeReturned(InvoiceDistribution $distribution, int $points): void
    {
        if ($points <= 0 || (int) $distribution->tier !== 3) {
            return;
        }

        $buyer = User::query()->find($distribution->to_user_id);
        if (! $buyer) {
            throw new \DomainException('تعذّر تحديد مشتري التوزيع لاسترجاع النقاط');
        }

        $wallet = $buyer->wallet();
        $balance = (int) $wallet->balance_points;

        if ($balance < $points) {
            throw new \DomainException(
                "لا يمكن عمل مرتجع بعد صرف النقاط — المطلوب استرجاع {$points} نقطة ورصيد السباك الحالي {$balance} نقطة فقط"
            );
        }

        $remainingOnDist = app(PointsConversionService::class)
            ->remainingPointsForDistribution($buyer, $distribution);

        if ($remainingOnDist < $points) {
            throw new \DomainException(
                "لا يمكن عمل مرتجع بعد صرف نقاط هذه الفاتورة — المتبقي من نقاط التوزيع {$remainingOnDist} والمطلوب {$points}"
            );
        }
    }

    protected function transferPoints(User $from, User $to, int $points, InvoiceReturn $return, User $actor): void
    {
        if ($points <= 0) {
            return;
        }

        $fromWallet = $from->wallet();
        $balance = (int) $fromWallet->balance_points;

        if ($balance < $points) {
            throw new \DomainException(
                "رصيد النقاط غير كافٍ لاستكمال المرتجع (المطلوب {$points} / المتاح {$balance})"
            );
        }

        $fromWallet->debitPoints($points, [
            'reason' => 'invoice_return_out',
            'return_id' => $return->id,
            'invoice_id' => $return->invoice_id,
            'distribution_id' => $return->distribution_id,
            'tier' => $return->tier,
        ], $actor, "مرتجع فاتورة {$return->return_number}");

        $to->wallet()->creditPoints($points, [
            'reason' => 'invoice_return_in',
            'return_id' => $return->id,
            'invoice_id' => $return->invoice_id,
            'distribution_id' => $return->distribution_id,
            'tier' => $return->tier,
            'debited_from_balance' => $points,
            'requested_points' => $points,
        ], $actor, "استلام مرتجع {$return->return_number}");
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceReturn;
use App\Models\InvoiceReturnItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvoiceReturnService
{
    /**
     * Return goods on a confirmed distribution (tier 2 or 3).
     *
     * Points move back up the chain:
     * - Debit the buyer (original to_user)
     * - Credit the seller (original from_user)
     * - Update outgoing invoice net points
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
            throw new \DomainException('لا يمكن عمل مرتجع إلا على توزيع مؤكّد');
        }

        if (! in_array((int) $distribution->tier, [2, 3], true)) {
            throw new \DomainException('المرتجعات متاحة لتوزيعات الجملة→قطاعي أو قطاعي→سباك فقط');
        }

        $this->assertActorCanReturn($distribution, $actor);

        $prepared = $this->prepareLines($distribution, $lines);

        if ($prepared === []) {
            throw new \DomainException('أضف كمية مرتجعة واحدة على الأقل');
        }

        $documentInvoice ??= $this->resolveDocumentInvoice($distribution);

        return DB::transaction(function () use ($distribution, $prepared, $actor, $note, $documentInvoice) {
            $totalQty = 0;
            $totalPoints = 0;

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

                $distItem->returned_quantity = (int) $distItem->returned_quantity + $row['quantity'];
                $distItem->save();

                $totalQty += $row['quantity'];
                $totalPoints += $row['points_value'];
            }

            $return = InvoiceReturn::create([
                'invoice_id' => $documentInvoice->id,
                'distribution_id' => $distribution->id,
                'from_user_id' => $distribution->to_user_id,
                'to_user_id' => $distribution->from_user_id,
                'tier' => $distribution->tier,
                'status' => 'confirmed',
                'total_quantity' => $totalQty,
                'total_points' => $totalPoints,
                'note' => $note,
                'created_by' => $actor->id,
                'confirmed_at' => now(),
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

            $this->transferPoints(
                from: User::findOrFail($distribution->to_user_id),
                to: User::findOrFail($distribution->from_user_id),
                points: $totalPoints,
                return: $return,
                actor: $actor,
            );

            $this->syncOutgoingInvoiceTotals($documentInvoice->fresh(['sourceDistribution.items', 'returns']));

            return $return->load(['items.product.translations', 'fromUser', 'toUser']);
        });
    }

    /**
     * @param  array<int, array{invoice_item_id:int, quantity:int}>  $lines
     */
    public function returnOutgoingInvoice(Invoice $invoice, array $lines, User $actor, ?string $note = null): InvoiceReturn
    {
        if (! $invoice->isWholesalePos() || $invoice->invoice_flow !== 'outgoing') {
            throw new \DomainException('المرتجع متاح على فواتير الصادر (جملة → قطاعي) فقط');
        }

        $distribution = $invoice->sourceDistribution
            ?? InvoiceDistribution::query()->whereKey($invoice->source_distribution_id)->first();

        if (! $distribution) {
            throw new \DomainException('تعذّر العثور على توزيع الفاتورة المرتبط');
        }

        return $this->returnDistribution($distribution, $lines, $actor, $note, $invoice);
    }

    /**
     * @return array<int, array{invoice_item_id:int, product_name:string, returnable:int, points_per_unit:float, sold:int, returned:int}>
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

            $lines[] = [
                'invoice_item_id' => (int) $distItem->invoice_item_id,
                'product_name' => localized_name($distItem->invoiceItem?->product, 'name', 'منتج'),
                'sold' => (int) $distItem->quantity,
                'returned' => (int) ($distItem->returned_quantity ?? 0),
                'returnable' => $returnable,
                'points_per_unit' => (float) ($distItem->invoiceItem?->points_per_unit ?? 0),
            ];
        }

        return $lines;
    }

    /**
     * Summary for invoice UI (outgoing preferred).
     *
     * @return array{
     *   sold_qty:int, returned_qty:int, net_qty:int,
     *   sold_points:int, returned_points:int, net_points:int,
     *   returns_count:int
     * }
     */
    public function invoiceReturnSummary(Invoice $invoice): array
    {
        $invoice->loadMissing(['returns', 'sourceDistribution.items']);

        $returnedQty = (int) $invoice->returns->where('status', 'confirmed')->sum('total_quantity');
        $returnedPoints = (int) $invoice->returns->where('status', 'confirmed')->sum('total_points');
        $returnsCount = $invoice->returns->where('status', 'confirmed')->count();

        if ($invoice->isOutgoing() && $invoice->sourceDistribution) {
            $soldQty = (int) $invoice->sourceDistribution->items->sum('quantity');
            $soldPoints = (int) $invoice->sourceDistribution->items->sum('points_value');
        } else {
            $soldQty = (int) $invoice->items->sum('quantity');
            $soldPoints = (int) ($invoice->points_awarded ?: $invoice->items->sum('total_points'));
        }

        return [
            'sold_qty' => $soldQty,
            'returned_qty' => $returnedQty,
            'net_qty' => max(0, $soldQty - $returnedQty),
            'sold_points' => $soldPoints,
            'returned_points' => $returnedPoints,
            'net_points' => max(0, $soldPoints - $returnedPoints),
            'returns_count' => $returnsCount,
        ];
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

    protected function returnableQtyForItem(InvoiceDistribution $distribution, InvoiceDistributionItem $distItem): int
    {
        $returnable = (int) $distItem->quantity - (int) ($distItem->returned_quantity ?? 0);

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
                throw new \DomainException('أحد البنود غير موجود في هذا التوزيع');
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
            throw new \DomainException('غير مصرّح لك بعمل مرتجع على هذا التوزيع');
        }
    }

    protected function transferPoints(User $from, User $to, int $points, InvoiceReturn $return, User $actor): void
    {
        if ($points <= 0) {
            return;
        }

        $fromWallet = $from->wallet();
        $balance = (int) $fromWallet->balance_points;
        $debit = min($points, max(0, $balance));

        if ($debit > 0) {
            $fromWallet->debitPoints($debit, [
                'reason' => 'invoice_return_out',
                'return_id' => $return->id,
                'invoice_id' => $return->invoice_id,
                'distribution_id' => $return->distribution_id,
                'tier' => $return->tier,
            ], $actor, "مرتجع فاتورة {$return->return_number}");
        }

        $to->wallet()->creditPoints($points, [
            'reason' => 'invoice_return_in',
            'return_id' => $return->id,
            'invoice_id' => $return->invoice_id,
            'distribution_id' => $return->distribution_id,
            'tier' => $return->tier,
            'debited_from_balance' => $debit,
            'requested_points' => $points,
        ], $actor, "استلام مرتجع {$return->return_number}");
    }
}

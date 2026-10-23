<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\DB;

class DistributionService
{
    public function createDistribution(
        Invoice $invoice,
        User $fromUser,
        User $toUser,
        int $tier,
        array $items,
        ?int $parentId = null,
        bool $skipCallerCheck = false,
    ): InvoiceDistribution {
        $this->validateTierRoles($tier, $fromUser, $toUser);
        if (! $skipCallerCheck) {
            $this->validateCallerIdentity($fromUser, $tier, $toUser);
        }
        $this->validateQuantities($invoice, $items, $tier, $parentId);

        $distribution = DB::transaction(function () use ($invoice, $fromUser, $toUser, $tier, $items, $parentId) {
            $created = InvoiceDistribution::create([
                'invoice_id' => $invoice->id,
                'from_user_id' => $fromUser->id,
                'to_user_id' => $toUser->id,
                'parent_distribution_id' => $parentId,
                'tier' => $tier,
                'status' => 'draft',
            ]);

            $pointsPolicy = app(\App\Services\Points\CatalogPointsPolicy::class);

            foreach ($items as $itemData) {
                $invoiceItem = $invoice->items()->with('product')->findOrFail($itemData['invoice_item_id']);
                // Stock lot rate (receive snapshot) so old/new invoices stay separate & math matches qty×ppu.
                $pointsValue = $pointsPolicy->awardForLotSale(
                    $invoiceItem->product,
                    (int) $itemData['quantity'],
                    (float) $invoiceItem->points_per_unit,
                );

                InvoiceDistributionItem::create([
                    'distribution_id' => $created->id,
                    'invoice_item_id' => $itemData['invoice_item_id'],
                    'quantity' => $itemData['quantity'],
                    'points_value' => $pointsValue,
                ]);
            }

            $created = $created->load('items.invoiceItem.product');

            // Document invoice for wholesale→retail (2) and retail→plumber (3).
            if (in_array($tier, [2, 3], true)) {
                app(OutgoingInvoiceService::class)->createFromDistribution($created);
            }

            return $created;
        });

        // WhatsApp: InvoiceObserver fires on outgoing Invoice::create (tier 2).
        // Confirm / points alerts come from InvoiceDistributionObserver.

        $this->bustStockCaches($distribution);

        return $distribution;
    }

    protected function bustStockCaches(InvoiceDistribution $distribution): void
    {
        $inventory = app(NetworkInventoryService::class);
        if ((int) $distribution->tier === 2 && $distribution->from_user_id) {
            $inventory->forgetWholesalerStockCache((int) $distribution->from_user_id);
        }
        if ((int) $distribution->tier === 1 && $distribution->to_user_id) {
            $inventory->forgetWholesalerStockCache((int) $distribution->to_user_id);
        }
    }

    private function validateTierRoles(int $tier, User $from, User $to): void
    {
        $rules = [
            1 => ['from' => ['super_admin', 'admin'], 'to' => ['wholesale_distributor']],
            // Tier 2 also allows warehouse→wholesaler transfers (central warehouse supply).
            2 => ['from' => ['wholesale_distributor'], 'to' => ['retail_trader', 'wholesale_distributor']],
            3 => ['from' => ['retail_trader'], 'to' => ['plumber']],
        ];

        if (! isset($rules[$tier])) {
            throw new \DomainException("Tier {$tier} غير صالح");
        }

        if (! in_array($from->role, $rules[$tier]['from'], true)) {
            throw new \DomainException('دور المُوزِّع غير صالح لهذه الطبقة');
        }

        if (! in_array($to->role, $rules[$tier]['to'], true)) {
            throw new \DomainException('دور المستلم غير صالح لهذه الطبقة');
        }
    }

    private function validateCallerIdentity(User $fromUser, ?int $tier = null, ?User $toUser = null): void
    {
        $caller = auth()->user();

        if (! $caller) {
            throw new \DomainException('يجب تسجيل الدخول أولاً');
        }

        if (in_array($caller->role, ['super_admin', 'admin'], true)) {
            return;
        }

        if ((int) $caller->id !== (int) $fromUser->id) {
            throw new \DomainException('لا يمكنك إنشاء توزيع باسم مستخدم آخر');
        }

        if ($toUser && (int) $caller->id === (int) $toUser->id) {
            throw new \DomainException('لا يمكنك التوزيع على نفسك');
        }
    }

    private function validateQuantities(Invoice $invoice, array $items, int $tier, ?int $parentId): void
    {
        foreach ($items as $itemData) {
            $invoiceItem = $invoice->items()->with('product.translations')->findOrFail($itemData['invoice_item_id']);
            $available = $invoiceItem->availableQuantityForTier($tier, $parentId);

            if ($itemData['quantity'] > $available) {
                $productName = $invoiceItem->product->translate(app()->getLocale())?->name
                    ?? $invoiceItem->product->translate('en')?->name
                    ?? 'منتج';

                throw new \DomainException(
                    "الكمية المطلوبة ({$itemData['quantity']}) للمنتج «{$productName}» ".
                    "تتجاوز الكمية المتاحة ({$available})"
                );
            }

            if ($itemData['quantity'] <= 0) {
                throw new \DomainException('الكمية يجب أن تكون أكبر من صفر');
            }
        }
    }

    /**
     * Buyer (المستلم) confirms receipt of a draft distribution issued by the seller.
     * Stock/points settle only after this call — seller POS never auto-completes.
     *
     * @return array{new_badges:list,score_delta:int,level_changed:bool,distribution:InvoiceDistribution}
     */
    public function receiveAsBuyer(User $buyer, InvoiceDistribution $distribution): array
    {
        if (in_array($buyer->role, ['super_admin', 'admin'], true)) {
            // admins may force-receive for support
        } elseif ((int) $buyer->id !== (int) $distribution->to_user_id) {
            throw new \DomainException('تأكيد الاستلام متاح للمستلم فقط (الطرف الآخر)');
        }

        if ($distribution->status !== 'draft') {
            if (in_array($distribution->status, ['confirmed', 'points_awarded'], true)) {
                throw new \DomainException('تم تأكيد استلام هذا التوزيع مسبقاً');
            }

            throw new \DomainException('لا يمكن تأكيد استلام توزيع بحالته الحالية');
        }

        $reputation = $this->confirmDistribution($distribution->fresh(['items']));

        return $reputation + [
            'distribution' => $distribution->fresh(['items.invoiceItem.product', 'fromUser', 'toUser', 'invoice']),
        ];
    }

    /**
     * Retail trader confirms receipt of a wholesale outgoing invoice (POS or order).
     *
     * @return array{new_badges:list,score_delta:int,level_changed:bool,distribution:InvoiceDistribution,invoice:Invoice}
     */
    public function receiveOutgoingInvoiceAsBuyer(User $buyer, Invoice $invoice): array
    {
        if ((int) $invoice->counterparty_user_id !== (int) $buyer->id
            && ! in_array($buyer->role, ['super_admin', 'admin'], true)) {
            throw new \DomainException('هذه الفاتورة ليست لك');
        }

        if ($invoice->invoice_flow !== 'outgoing' || $invoice->invoice_type !== 'wholesale_pos') {
            throw new \DomainException('نوع الفاتورة غير صالح لتأكيد الاستلام');
        }

        $distribution = InvoiceDistribution::query()
            ->whereKey($invoice->source_distribution_id)
            ->first();

        if (! $distribution) {
            throw new \DomainException('لا يوجد توزيع مرتبط بهذه الفاتورة');
        }

        $result = $this->receiveAsBuyer($buyer, $distribution);

        return $result + [
            'invoice' => $invoice->fresh(),
        ];
    }

    /**
     * @return array{new_badges:list,score_delta:int,level_changed:bool}
     */
    public function confirmDistribution(InvoiceDistribution $distribution): array
    {
        if ($distribution->status === 'points_awarded') {
            throw new \DomainException('لا يمكن تعديل توزيع تم منح نقاطه');
        }

        $distribution->confirm();
        $distribution->loadMissing('items');
        $this->bustStockCaches($distribution);

        $totalPoints = (int) $distribution->items->sum('points_value');

        match ((int) $distribution->tier) {
            1 => null,
            2 => null,
            3 => $this->awardPointsToPlumber($distribution, $totalPoints),
            default => null,
        };

        $this->syncOutgoingInvoiceStatus($distribution);

        $reputation = ['new_badges' => [], 'score_delta' => 0, 'level_changed' => false];
        if ((int) $distribution->tier === 3 && $distribution->to_user_id) {
            try {
                $reputation = \App\Jobs\Reputation\RecomputeScoreJob::runSync(
                    (int) $distribution->to_user_id,
                    'distribution_confirmed',
                    (int) $distribution->id
                );
            } catch (\Throwable) {
                // reputation must never break distribution confirm
            }
        }

        return $reputation;
    }

    private function awardPointsToPlumber(InvoiceDistribution $distribution, int $points): void
    {
        if ($points <= 0) {
            return;
        }

        DB::transaction(function () use ($distribution, $points) {
            $plumber = User::query()
                ->whereKey($distribution->to_user_id)
                ->where('role', 'plumber')
                ->lockForUpdate()
                ->firstOrFail();

            $wallet = WalletAccount::firstOrCreate(
                ['owner_id' => $plumber->id, 'currency' => 'LYD'],
                ['balance_cents' => 0, 'balance_points' => 0]
            );

            $wallet->creditPoints($points, [
                'reason' => 'distribution_points',
                'distribution_id' => $distribution->id,
                'invoice_id' => $distribution->invoice_id,
                'from_user_id' => $distribution->from_user_id,
                'tier' => 3,
            ], auth()->user(), 'نقاط مبيعات — تاجر قطاعي');

            $distribution->update([
                'status' => 'points_awarded',
                'points_awarded_at' => now(),
            ]);
        });
    }

    private function syncOutgoingInvoiceStatus(InvoiceDistribution $distribution): void
    {
        if (! in_array((int) $distribution->tier, [2, 3], true)) {
            return;
        }

        Invoice::query()
            ->where('source_distribution_id', $distribution->id)
            ->update([
                'status' => 'approved',
                'approved_at' => $distribution->confirmed_at ?? now(),
                'points_awarded' => (int) $distribution->items->sum('points_value'),
            ]);
    }

    public function getDistributionSummary(Invoice $invoice): array
    {
        $invoice->loadMissing('items.product.translations');

        return $invoice->items->map(function ($item) use ($invoice) {
            $distributed = $invoice->distributions()
                ->where('status', '!=', 'draft')
                ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
                ->where('idi.invoice_item_id', $item->id)
                ->selectRaw('invoice_distributions.tier, SUM(idi.quantity) as total_qty')
                ->groupBy('invoice_distributions.tier')
                ->pluck('total_qty', 'tier');

            return [
                'product' => $item->product->translate(app()->getLocale())?->name
                    ?? $item->product->translate('en')?->name
                    ?? 'منتج',
                'total_qty' => $item->quantity,
                'tier1_distributed' => $distributed->get(1, 0),
                'tier2_distributed' => $distributed->get(2, 0),
                'tier3_distributed' => $distributed->get(3, 0),
                'total_points' => $item->total_points,
                'points_awarded' => $invoice->distributions()
                    ->where('tier', 3)
                    ->where('status', 'points_awarded')
                    ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
                    ->where('idi.invoice_item_id', $item->id)
                    ->sum('idi.points_value'),
            ];
        })->toArray();
    }
}

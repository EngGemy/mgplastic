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

        return DB::transaction(function () use ($invoice, $fromUser, $toUser, $tier, $items, $parentId) {
            $distribution = InvoiceDistribution::create([
                'invoice_id' => $invoice->id,
                'from_user_id' => $fromUser->id,
                'to_user_id' => $toUser->id,
                'parent_distribution_id' => $parentId,
                'tier' => $tier,
                'status' => 'draft',
            ]);

            foreach ($items as $itemData) {
                $invoiceItem = $invoice->items()->findOrFail($itemData['invoice_item_id']);
                $pointsValue = (int) floor($itemData['quantity'] * $invoiceItem->points_per_unit);

                InvoiceDistributionItem::create([
                    'distribution_id' => $distribution->id,
                    'invoice_item_id' => $itemData['invoice_item_id'],
                    'quantity' => $itemData['quantity'],
                    'points_value' => $pointsValue,
                ]);
            }

            $distribution = $distribution->load('items.invoiceItem.product');

            if ($tier === 2) {
                app(OutgoingInvoiceService::class)->createFromDistribution($distribution);
            }

            return $distribution;
        });
    }

    private function validateTierRoles(int $tier, User $from, User $to): void
    {
        $rules = [
            1 => ['from' => ['super_admin', 'admin'], 'to' => 'wholesale_distributor'],
            2 => ['from' => ['wholesale_distributor'], 'to' => 'retail_trader'],
            3 => ['from' => ['retail_trader'], 'to' => 'plumber'],
        ];

        if (! isset($rules[$tier])) {
            throw new \DomainException("Tier {$tier} غير صالح");
        }

        if (! in_array($from->role, $rules[$tier]['from'], true)) {
            throw new \DomainException('دور المُوزِّع غير صالح لهذه الطبقة');
        }

        if ($to->role !== $rules[$tier]['to']) {
            throw new \DomainException("المستلم يجب أن يكون {$rules[$tier]['to']}");
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

    public function confirmDistribution(InvoiceDistribution $distribution): void
    {
        if ($distribution->status === 'points_awarded') {
            throw new \DomainException('لا يمكن تعديل توزيع تم منح نقاطه');
        }

        $distribution->confirm();
        $distribution->loadMissing('items');

        $totalPoints = (int) $distribution->items->sum('points_value');

        match ((int) $distribution->tier) {
            1 => null,
            2 => null,
            3 => $this->awardPointsToPlumber($distribution, $totalPoints),
            default => null,
        };

        $this->syncOutgoingInvoiceStatus($distribution);
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
        if ((int) $distribution->tier !== 2) {
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

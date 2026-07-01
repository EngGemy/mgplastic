<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RetailDistributionPosService
{
    public function __construct(
        protected NetworkInventoryService $inventory,
        protected DistributionService $distributions,
    ) {}

    /**
     * @param  array<int, array{product_id:int, quantity:int}>  $lines
     */
    public function issueToRetailTrader(User $wholesaler, User $retailTrader, array $lines, User $issuedBy): array
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            throw new \DomainException('يجب أن يكون البائع موزع جملة');
        }

        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('يجب اختيار تاجر قطاعي');
        }

        if ((int) $retailTrader->parent_distributor_id !== (int) $wholesaler->id) {
            throw new \DomainException('هذا التاجر القطاعي غير تابع لمتجرك');
        }

        if (empty($lines)) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل من مخزونك');
        }

        $stock = $this->inventory->stockForWholesaler($wholesaler);

        if ($stock->isEmpty()) {
            throw new \DomainException('مخزونك فارغ — لا توجد فواتير وارد معتمدة بمنتجات متاحة');
        }

        $requested = collect($lines)
            ->groupBy('product_id')
            ->map(fn ($group) => (int) $group->sum('quantity'))
            ->all();

        $requiredPoints = $this->inventory->totalPointsForRequest($stock, $requested);
        $this->inventory->assertPointsBalance($wholesaler, $requiredPoints);

        $groups = $this->inventory->allocateFromStock($stock, $requested);

        return DB::transaction(function () use ($groups, $wholesaler, $retailTrader, $issuedBy) {
            $outgoingInvoices = [];

            foreach ($groups as $group) {
                $invoice = Invoice::query()->findOrFail($group['invoice_id']);

                $distribution = $this->distributions->createDistribution(
                    invoice: $invoice,
                    fromUser: $wholesaler,
                    toUser: $retailTrader,
                    tier: 2,
                    items: $group['items'],
                    parentId: $group['parent_distribution_id'],
                );

                $this->distributions->confirmDistribution($distribution->fresh(['items']));

                $outgoing = Invoice::query()
                    ->where('source_distribution_id', $distribution->id)
                    ->first();

                if ($outgoing) {
                    $outgoingInvoices[] = $outgoing;
                }
            }

            return $outgoingInvoices;
        });
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlumberDistributionPosService
{
    public function __construct(
        protected NetworkInventoryService $inventory,
        protected DistributionService $distributions,
    ) {}

    /**
     * @param  array<int, array{product_id:int, quantity:int}>  $lines
     */
    public function issueToPlumber(User $retailTrader, User $plumber, array $lines, User $issuedBy): array
    {
        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('يجب أن يكون البائع تاجر قطاعي');
        }

        if (! $plumber->isPlumber()) {
            throw new \DomainException('يجب اختيار سباك');
        }

        if (! $plumber->is_active) {
            throw new \DomainException('هذا السباك موقوف');
        }

        if (empty($lines)) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل من مخزونك');
        }

        $stock = $this->inventory->stockForRetailTrader($retailTrader);

        if ($stock->isEmpty()) {
            throw new \DomainException('مخزونك فارغ — لا توجد بضاعة واصلة من موزع الجملة');
        }

        $requested = collect($lines)
            ->groupBy('product_id')
            ->map(fn ($group) => (int) $group->sum('quantity'))
            ->all();

        // Stock slots are the source of truth for retail→plumber.
        // Plumber wallet is credited on confirm (tier 3); seller wallet is not debited.
        $groups = $this->inventory->allocateFromStock($stock, $requested);

        return DB::transaction(function () use ($groups, $retailTrader, $plumber) {
            $distributions = [];

            foreach ($groups as $group) {
                $invoice = Invoice::query()->findOrFail($group['invoice_id']);

                $distribution = $this->distributions->createDistribution(
                    invoice: $invoice,
                    fromUser: $retailTrader,
                    toUser: $plumber,
                    tier: 3,
                    items: $group['items'],
                    parentId: $group['parent_distribution_id'],
                );

                $this->distributions->confirmDistribution($distribution->fresh(['items']));
                $distributions[] = $distribution->fresh(['items', 'invoice']);
            }

            return $distributions;
        });
    }
}

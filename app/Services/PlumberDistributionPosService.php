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
        protected OrderService $orders,
    ) {}

    /**
     * @param  array<int, array{product_id:int, quantity?:int, boxes?:int}>  $lines
     * @return array{distributions:list, reputation:array{new_badges:list,score_delta:int,level_changed:bool}}
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

        app(PlumberRetailLinkService::class)->assertInNetwork($retailTrader, $plumber);

        if (empty($lines)) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل من مخزونك');
        }

        $stock = $this->inventory->stockForRetailTrader($retailTrader);

        if ($stock->isEmpty()) {
            throw new \DomainException('مخزونك فارغ — لا توجد بضاعة واصلة من موزع الجملة');
        }

        $lines = $this->orders->expandLinesToPieces($lines, enforceFactoryBoxes: false);
        if ($lines === []) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل من مخزونك');
        }

        $requested = collect($lines)
            ->groupBy('product_id')
            ->map(fn ($group) => (int) $group->sum('quantity'))
            ->all();

        // Stock slots are the source of truth for retail→plumber.
        // Plumber wallet is credited on confirm (tier 3); seller wallet is not debited.
        $groups = $this->inventory->allocateFromStock($stock, $requested);

        // Draft only — plumber must confirm receipt before points are awarded.
        return DB::transaction(function () use ($groups, $retailTrader, $plumber) {
            $created = [];

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

                $created[] = $distribution->fresh(['items', 'invoice', 'fromUser', 'toUser']);
            }

            return [
                'distributions' => $created,
                'reputation' => ['new_badges' => [], 'score_delta' => 0, 'level_changed' => false],
                'pending_receipt' => true,
            ];
        });
    }
}

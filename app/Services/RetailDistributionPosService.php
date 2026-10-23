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
        protected RetailNetworkLinkService $retailLinks,
        protected OrderService $orders,
    ) {}

    /**
     * @param  array<int, array{product_id:int, quantity?:int, boxes?:int}>  $lines
     */
    public function issueToRetailTrader(User $wholesaler, User $retailTrader, array $lines, User $issuedBy): array
    {
        if (! $wholesaler->isWholesaleDistributor()) {
            throw new \DomainException('يجب أن يكون البائع موزع جملة');
        }

        if (! $retailTrader->isRetailTrader()) {
            throw new \DomainException('يجب اختيار تاجر قطاعي');
        }

        $this->retailLinks->assertLinked($wholesaler, $retailTrader);

        if (empty($lines)) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل من مخزونك');
        }

        $stock = $this->inventory->stockForWholesaler($wholesaler);

        if ($stock->isEmpty()) {
            throw new \DomainException('مخزونك فارغ — لا توجد فواتير وارد معتمدة بمنتجات متاحة');
        }

        // Boxed catalog lines: prefer `boxes` → expand to pieces before stock allocation.
        $lines = $this->orders->expandLinesToPieces($lines, enforceFactoryBoxes: false);
        if ($lines === []) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل من مخزونك');
        }

        $requested = collect($lines)
            ->groupBy('product_id')
            ->map(fn ($group) => (int) $group->sum('quantity'))
            ->all();

        // Stock slots are the source of truth for wholesale→retail.
        // Wallet points are only settled at plumber (tier 3).
        $groups = $this->inventory->allocateFromStock($stock, $requested);

        return DB::transaction(function () use ($groups, $wholesaler, $retailTrader, $issuedBy) {
            $outgoingInvoices = [];

            foreach ($groups as $group) {
                $invoice = Invoice::query()->findOrFail($group['invoice_id']);

                // Leave draft — retail must POST …/receive before stock settles.
                $distribution = $this->distributions->createDistribution(
                    invoice: $invoice,
                    fromUser: $wholesaler,
                    toUser: $retailTrader,
                    tier: 2,
                    items: $group['items'],
                    parentId: $group['parent_distribution_id'],
                );

                $outgoing = Invoice::query()
                    ->where('source_distribution_id', $distribution->id)
                    ->first();

                if ($outgoing) {
                    $outgoingInvoices[] = $outgoing->fresh(['sourceDistribution']);
                }
            }

            return $outgoingInvoices;
        });
    }
}

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

        $requested = collect($lines)
            ->groupBy('product_id')
            ->map(fn ($group) => (int) $group->sum('quantity'))
            ->all();

        // Stock slots are the source of truth for retail→plumber.
        // Plumber wallet is credited on confirm (tier 3); seller wallet is not debited.
        $groups = $this->inventory->allocateFromStock($stock, $requested);

        $pack = DB::transaction(function () use ($groups, $retailTrader, $plumber) {
            $created = [];
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
                );

                $rep = $this->distributions->confirmDistribution($distribution->fresh(['items']));
                $created[] = $distribution->fresh(['items', 'invoice']);
                $reputation['score_delta'] += (int) ($rep['score_delta'] ?? 0);
                $reputation['level_changed'] = $reputation['level_changed'] || ! empty($rep['level_changed']);
                $reputation['new_badges'] = array_merge($reputation['new_badges'], $rep['new_badges'] ?? []);
            }

            return ['distributions' => $created, 'reputation' => $reputation];
        });

        $distributions = $pack['distributions'];
        $totalPoints = (int) collect($distributions)->sum(
            fn ($distribution) => (int) $distribution->items->sum('points_value')
        );
        $lineCount = (int) collect($distributions)->sum(
            fn ($distribution) => $distribution->items->count()
        );

        try {
            app(WhatsAppBusinessAlerts::class)->plumberSale(
                $plumber,
                $retailTrader,
                $totalPoints,
                max(1, $lineCount),
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[WhatsApp] plumber sale notify skipped', [
                'plumber_id' => $plumber->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $pack;
    }
}

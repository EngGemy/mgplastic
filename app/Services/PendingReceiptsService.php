<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\User;

/**
 * Counts draft buyer receipts awaiting confirmation (retail / plumber).
 * Shared by pending-receipts list APIs and home layout badge.
 */
class PendingReceiptsService
{
    public const DEEP_LINK = 'mgplastic://pending-receipts';

    public const SCREEN = 'pending-receipts';

    public function countFor(?User $user): int
    {
        if (! $user) {
            return 0;
        }

        if ($user->isPlumber()) {
            return $this->countForPlumber($user);
        }

        if ($user->isRetailTrader()) {
            return $this->countForRetailTrader($user);
        }

        return 0;
    }

    public function countForPlumber(User $plumber): int
    {
        return (int) InvoiceDistribution::query()
            ->where('to_user_id', $plumber->id)
            ->where('tier', 3)
            ->where('status', 'draft')
            ->count();
    }

    public function countForRetailTrader(User $retail): int
    {
        return (int) Invoice::query()
            ->where('invoice_type', 'wholesale_pos')
            ->where('invoice_flow', 'outgoing')
            ->where('counterparty_user_id', $retail->id)
            ->where('status', 'pending_review')
            ->whereHas('sourceDistribution', fn ($q) => $q
                ->where('tier', 2)
                ->where('to_user_id', $retail->id)
                ->where('status', 'draft'))
            ->count();
    }

    public function deepLink(?int $id = null): string
    {
        if ($id !== null && $id > 0) {
            return self::DEEP_LINK.'/'.$id;
        }

        return self::DEEP_LINK;
    }

    /**
     * Home section card when the user has pending receipts.
     *
     * @return list<array<string, mixed>>
     */
    public function homeItems(int $count): array
    {
        $title = $count === 1
            ? 'بضاعة بانتظار تأكيد الاستلام'
            : "لديك {$count} فواتير بانتظار تأكيد الاستلام";

        return [[
            'id' => 'pending',
            'title' => $title,
            'subtitle' => 'اضغط للتأكيد حتى يدخل المخزون والنقاط',
            'badge_count' => $count,
            'link' => self::DEEP_LINK,
            'deep_link' => self::DEEP_LINK,
        ]];
    }
}

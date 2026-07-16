<?php

namespace App\Http\Resources\Mobile;

use App\Models\WalletAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalletAccount */
class WalletResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pointsHistory = $this->relationLoaded('transactions')
            ? $this->transactions
            : $this->transactions()
                ->where(function ($q) {
                    $q->where('points_delta', '!=', 0)
                        ->orWhereIn('type', ['credit', 'debit']);
                })
                ->latest()
                ->limit(30)
                ->get();

        $lifetimeEarned = (int) $this->transactions()
            ->where('points_delta', '>', 0)
            ->sum('points_delta');

        $lifetimeSpent = (int) abs((int) $this->transactions()
            ->where('points_delta', '<', 0)
            ->sum('points_delta'));

        return [
            'id' => $this->id,
            'currency' => $this->currency,

            // الرصيد الحالي
            'balance_points' => (int) $this->balance_points,
            'balance_cents' => (int) $this->balance_cents,
            'balance_formatted' => number_format(((int) $this->balance_cents) / 100, 2).' د.ل',

            // ملخص النقاط
            'total_points_earned' => $lifetimeEarned,
            'total_points_spent' => $lifetimeSpent,
            'points_summary' => [
                'current' => (int) $this->balance_points,
                'earned' => $lifetimeEarned,
                'spent' => $lifetimeSpent,
            ],

            // تاريخ ظهور/حركة النقاط
            'points_history' => WalletTransactionResource::collection(
                $pointsHistory->filter(fn ($tx) => (int) $tx->points_delta !== 0)->values()
            ),
            'recent_transactions' => WalletTransactionResource::collection($pointsHistory->take(10)->values()),
        ];
    }
}

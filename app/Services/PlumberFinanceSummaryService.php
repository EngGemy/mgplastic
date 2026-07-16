<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PlumberFinanceSummaryService
{
    /**
     * @return array{
     *   wallet:?WalletAccount,
     *   balance_points:int,
     *   balance_cents:int,
     *   balance_money_formatted:string,
     *   earned_points:int,
     *   converted_points:int,
     *   debited_points:int,
     *   withdrawn_paid_cents:int,
     *   withdrawn_paid_formatted:string,
     *   withdrawn_pending_cents:int,
     *   withdrawn_pending_formatted:string,
     *   withdrawals_count:int,
     *   withdrawals_paid_count:int,
     *   withdrawals_pending_count:int,
     *   withdrawals:Collection,
     *   transactions:Collection
     * }
     */
    public function for(User $plumber): array
    {
        $wallet = WalletAccount::query()
            ->where('owner_id', $plumber->id)
            ->where('currency', 'LYD')
            ->first();

        $balancePoints = (int) ($wallet?->balance_points ?? 0);
        $balanceCents = (int) ($wallet?->balance_cents ?? 0);

        $earnedPoints = 0;
        $convertedPoints = 0;
        $debitedPoints = 0;
        $transactions = collect();

        if ($wallet) {
            $earnedPoints = (int) $wallet->transactions()
                ->where('points_delta', '>', 0)
                ->sum('points_delta');

            $convertedPoints = (int) abs((int) $wallet->transactions()
                ->where('type', 'conversion')
                ->sum('points_delta'));

            $debitedPoints = (int) abs((int) $wallet->transactions()
                ->where('points_delta', '<', 0)
                ->sum('points_delta'));

            $transactions = $wallet->transactions()
                ->latest('id')
                ->limit(40)
                ->get();
        }

        $withdrawals = collect();
        $paidCents = 0;
        $pendingCents = 0;
        $paidCount = 0;
        $pendingCount = 0;

        if (Schema::hasTable('withdrawal_requests')) {
            $withdrawals = WithdrawalRequest::query()
                ->where('plumber_id', $plumber->id)
                ->latest('id')
                ->limit(30)
                ->get();

            // Prefer DB totals (list is limited to recent rows).
            $paidCents = (int) WithdrawalRequest::query()
                ->where('plumber_id', $plumber->id)
                ->where('status', 'paid')
                ->sum('amount_cents');
            $pendingCents = (int) WithdrawalRequest::query()
                ->where('plumber_id', $plumber->id)
                ->whereIn('status', ['pending', 'approved'])
                ->sum('amount_cents');
            $paidCount = (int) WithdrawalRequest::query()
                ->where('plumber_id', $plumber->id)
                ->where('status', 'paid')
                ->count();
            $pendingCount = (int) WithdrawalRequest::query()
                ->where('plumber_id', $plumber->id)
                ->whereIn('status', ['pending', 'approved'])
                ->count();
        }

        return [
            'wallet' => $wallet,
            'balance_points' => $balancePoints,
            'balance_cents' => $balanceCents,
            'balance_money_formatted' => number_format($balanceCents / 100, 2).' د.ل',
            'earned_points' => $earnedPoints,
            'converted_points' => $convertedPoints,
            'debited_points' => $debitedPoints,
            'withdrawn_paid_cents' => $paidCents,
            'withdrawn_paid_formatted' => number_format($paidCents / 100, 2).' د.ل',
            'withdrawn_pending_cents' => $pendingCents,
            'withdrawn_pending_formatted' => number_format($pendingCents / 100, 2).' د.ل',
            'withdrawals_count' => $withdrawals->count(),
            'withdrawals_paid_count' => $paidCount,
            'withdrawals_pending_count' => $pendingCount,
            'withdrawals' => $withdrawals,
            'transactions' => $transactions,
        ];
    }

    public function transactionTypeLabel(?string $type): string
    {
        return match ($type) {
            'credit' => 'إضافة نقاط',
            'debit' => 'خصم نقاط',
            'conversion' => 'تحويل لفلوس',
            'withdrawal' => 'سحب / حجز',
            'adjustment' => 'تعديل إداري',
            default => $type ?: 'حركة',
        };
    }
}

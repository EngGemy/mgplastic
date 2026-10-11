<?php

namespace App\Ai\Tools;

use App\Models\InvoiceDistribution;
use App\Models\WalletAccount;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\Schema;

class ReportPointsTool extends AbstractTool
{
    public function name(): string
    {
        return 'report_points';
    }

    public function description(): string
    {
        return 'Points/wallet report: balances, awarded distributions, withdrawals.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'days' => ['type' => 'integer', 'description' => 'Window in days (default 30)'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $days = max(1, min(365, (int) ($arguments['days'] ?? 30)));
        $since = now()->subDays($days);

        $totalBalance = (int) WalletAccount::query()->sum('balance_points');
        $awardedDistributions = InvoiceDistribution::query()
            ->where('status', 'points_awarded')
            ->where('points_awarded_at', '>=', $since)
            ->count();

        $withdrawalsPending = WithdrawalRequest::query()->where('status', 'pending')->count();
        $withdrawalsPaidCents = (int) WithdrawalRequest::query()
            ->where('status', 'paid')
            ->where('paid_at', '>=', $since)
            ->sum('amount_cents');

        $txCount = 0;
        if (Schema::hasTable('wallet_transactions')) {
            $txCount = WalletTransaction::query()->where('created_at', '>=', $since)->count();
        }

        return [
            'window_days' => $days,
            'total_balance_points' => $totalBalance,
            'distributions_points_awarded_in_window' => $awardedDistributions,
            'wallet_transactions_in_window' => $txCount,
            'withdrawals_pending' => $withdrawalsPending,
            'withdrawals_paid_cents_in_window' => $withdrawalsPaidCents,
        ];
    }
}

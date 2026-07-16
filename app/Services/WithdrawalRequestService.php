<?php

namespace App\Services;

use App\Models\WalletAccount;
use App\Models\WithdrawalRequest;
use App\Filament\Support\WithdrawalPaymentForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WithdrawalRequestService
{
    /** @param  array<string, mixed>  $data */
    public static function markPaid(WithdrawalRequest $req, array $data): void
    {
        $proof = WithdrawalPaymentForm::normalizeConfirmation($data);

        DB::transaction(function () use ($req, $proof) {
            $locked = WithdrawalRequest::query()
                ->whereKey($req->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new \DomainException('الطلب ليس قيد المراجعة');
            }

            $wallet = self::resolveWalletForRequest($locked);

            $locked->update([
                'status' => 'paid',
                'reviewed_by' => auth()->id(),
                'paid_at' => now(),
                'receipt_number' => $proof['receipt_number'],
                'transfer_number' => $proof['transfer_number'],
            ]);

            $wallet->transactions()->create([
                'type' => 'withdrawal',
                'amount_cents' => 0,
                'points_delta' => 0,
                'description' => 'تم صرف طلب السحب #'.$locked->id,
                'meta' => [
                    'withdrawal_id' => $locked->id,
                    'payout_status' => 'paid',
                    'amount_cents' => $locked->amount_cents,
                    'receipt_number' => $proof['receipt_number'],
                    'transfer_number' => $proof['transfer_number'],
                ],
                'related_type' => WithdrawalRequest::class,
                'related_id' => $locked->id,
                'created_by' => auth()->id(),
            ]);
        });
    }

    public static function rejectAndRefund(WithdrawalRequest $req, array $data): void
    {
        DB::transaction(function () use ($req, $data) {
            $locked = WithdrawalRequest::query()
                ->whereKey($req->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new \DomainException('الطلب ليس قيد المراجعة');
            }

            $wallet = self::resolveWalletForRequest($locked);

            $wallet->increment('balance_cents', $locked->amount_cents);

            $wallet->transactions()->create([
                'type' => 'adjustment',
                'amount_cents' => $locked->amount_cents,
                'points_delta' => 0,
                'description' => 'إرجاع رصيد طلب سحب مرفوض #'.$locked->id,
                'meta' => [
                    'withdrawal_id' => $locked->id,
                    'reason_ar' => $data['reason_ar'] ?? '',
                    'reason_en' => $data['reason_en'] ?? '',
                ],
                'related_type' => WithdrawalRequest::class,
                'related_id' => $locked->id,
                'created_by' => auth()->id(),
            ]);

            $locked->update([
                'status' => 'rejected',
                'reviewed_by' => auth()->id(),
                'rejection_reason' => [
                    'ar' => $data['reason_ar'] ?? '',
                    'en' => $data['reason_en'] ?? '',
                ],
            ]);
        });
    }

    /** @return array{pending: int, paid: int, rejected: int, total_amount_pending: int} */
    public static function stats(): array
    {
        return [
            'pending' => WithdrawalRequest::pendingCount(),
            'paid' => Schema::hasColumn('withdrawal_requests', 'status')
                ? WithdrawalRequest::query()->where('status', 'paid')->count()
                : 0,
            'rejected' => Schema::hasColumn('withdrawal_requests', 'status')
                ? WithdrawalRequest::query()->where('status', 'rejected')->count()
                : 0,
            'total_amount_pending' => Schema::hasColumn('withdrawal_requests', 'amount_cents')
                && Schema::hasColumn('withdrawal_requests', 'status')
                ? (int) WithdrawalRequest::query()->pending()->sum('amount_cents')
                : 0,
        ];
    }

    protected static function resolveWalletForRequest(WithdrawalRequest $request): WalletAccount
    {
        if ($request->wallet_account_id) {
            $wallet = WalletAccount::query()
                ->whereKey($request->wallet_account_id)
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                return $wallet;
            }
        }

        if ($request->plumber_id) {
            $wallet = WalletAccount::query()
                ->where('owner_id', $request->plumber_id)
                ->where('currency', 'LYD')
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                if (
                    Schema::hasColumn('withdrawal_requests', 'wallet_account_id')
                    && ! $request->wallet_account_id
                ) {
                    $request->forceFill(['wallet_account_id' => $wallet->id])->save();
                }

                return $wallet;
            }
        }

        throw new \DomainException('تعذّر العثور على محفظة طلب السحب');
    }
}

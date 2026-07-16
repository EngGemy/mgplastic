<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WalletAccount extends Model
{
    protected $fillable = ['owner_id', 'currency', 'balance_cents', 'balance_points'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_account_id');
    }

    public function creditPoints(int $points, array $meta = [], ?User $by = null, ?string $description = null): void
    {
        if ($points <= 0) {
            return;
        }

        DB::transaction(function () use ($points, $meta, $by, $description) {
            $wallet = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            $wallet->balance_points += $points;
            $wallet->save();

            [$relatedType, $relatedId] = $this->resolveTransactionRelated($meta);

            $wallet->transactions()->create([
                'type' => 'credit',
                'amount_cents' => 0,
                'points_delta' => $points,
                'description' => $description ?? 'Points credit',
                'meta' => $meta,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'created_by' => $by?->id,
            ]);
        });
    }

    public function debitPoints(int $points, array $meta = [], ?User $by = null, ?string $description = null): void
    {
        if ($points <= 0) {
            return;
        }

        DB::transaction(function () use ($points, $meta, $by, $description) {
            $wallet = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            $wallet->balance_points -= $points;
            $wallet->save();

            [$relatedType, $relatedId] = $this->resolveTransactionRelated($meta);

            $wallet->transactions()->create([
                'type' => 'debit',
                'amount_cents' => 0,
                'points_delta' => -$points,
                'description' => $description ?? 'Points debit',
                'meta' => $meta,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'created_by' => $by?->id,
            ]);
        });
    }

    /** @return array{0: class-string, 1: int|null} */
    private function resolveTransactionRelated(array $meta): array
    {
        $relatedType = match ($meta['reason'] ?? null) {
            'invoice_approval' => Invoice::class,
            'order_delivery' => Order::class,
            'distribution_points',
            'distribution_tier1',
            'distribution_tier2_in',
            'distribution_tier2_out',
            'distribution_tier3_in',
            'distribution_tier3_out' => InvoiceDistribution::class,
            default => InvoiceDistribution::class,
        };

        $relatedId = $meta['order_id'] ?? $meta['distribution_id'] ?? $meta['invoice_id'] ?? null;

        return [$relatedType, $relatedId];
    }
}

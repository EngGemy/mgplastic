<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class InvoiceDistribution extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'from_user_id',
        'to_user_id',
        'parent_distribution_id',
        'tier',
        'status',
        'confirmed_at',
        'points_awarded_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'points_awarded_at' => 'datetime',
        'tier' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_distribution_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_distribution_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceDistributionItem::class, 'distribution_id');
    }

    public function confirm(): void
    {
        if ($this->status === 'points_awarded') {
            throw new \DomainException('لا يمكن تعديل توزيع تم منح نقاطه');
        }

        if ($this->status === 'confirmed') {
            throw new \DomainException('هذا التوزيع مؤكد مسبقاً');
        }

        DB::transaction(function () {
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new \DomainException('تغيّرت حالة التوزيع — يرجى إعادة المحاولة');
            }

            $this->loadMissing('items.invoiceItem');

            foreach ($this->items as $item) {
                InvoiceItem::query()
                    ->whereKey($item->invoice_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $available = $item->invoiceItem->availableQuantityForTier(
                    $this->tier,
                    $this->parent_distribution_id
                );

                if ($item->quantity > $available) {
                    $productName = $item->invoiceItem->product?->translate('ar')?->name
                        ?? $item->invoiceItem->product?->translate('en')?->name
                        ?? 'منتج';

                    throw new \DomainException(
                        "الكمية ({$item->quantity}) للمنتج «{$productName}» ".
                        "تتجاوز المتاح ({$available})"
                    );
                }
            }

            $this->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);
        });
    }

    public function awardPointsToPlumber(): void
    {
        if ($this->tier !== 3) {
            throw new \LogicException('يمكن منح النقاط للسباكين فقط (tier 3)');
        }

        if ($this->status !== 'confirmed') {
            throw new \LogicException('يجب تأكيد التوزيع أولاً قبل منح النقاط');
        }

        DB::transaction(function () {
            $this->loadMissing('items');

            $totalPoints = $this->items->sum('points_value');

            if ($totalPoints <= 0) {
                return;
            }

            $wallet = WalletAccount::firstOrCreate(
                ['owner_id' => $this->to_user_id, 'currency' => 'LYD'],
                ['balance_cents' => 0, 'balance_points' => 0]
            );

            $wallet->creditPoints($totalPoints, [
                'reason' => 'distribution_points',
                'distribution_id' => $this->id,
                'invoice_id' => $this->invoice_id,
                'from_user_id' => $this->from_user_id,
                'tier' => $this->tier,
            ]);

            $this->update([
                'status' => 'points_awarded',
                'points_awarded_at' => now(),
            ]);
        });
    }

    public function getTotalPointsAttribute(): int
    {
        return $this->items->sum('points_value');
    }
}

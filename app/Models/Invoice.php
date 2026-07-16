<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'serial_number',
        'invoice_type',
        'invoice_flow',
        'parent_invoice_id',
        'counterparty_user_id',
        'source_distribution_id',
        'plumber_id','vendor_store_id','wholesale_distributor_id',
        'subtotal_cents','tax_cents','total_cents',
        'currency','number','attachment_path',
        'status','approved_at','reviewed_by','issued_by','rejection_reason',
        'profit_percent','points_awarded',
    ];

    protected $casts = [
        'approved_at'      => 'datetime',
        'rejection_reason' => 'array',
        'profit_percent'   => 'decimal:2',
    ];

    // علاقات
    public function plumber(){ return $this->belongsTo(User::class, 'plumber_id'); }
    public function wholesaleDistributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wholesale_distributor_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_user_id');
    }

    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_id');
    }

    public function sourceDistribution(): BelongsTo
    {
        return $this->belongsTo(InvoiceDistribution::class, 'source_distribution_id');
    }

    public function isIncoming(): bool
    {
        return ($this->invoice_flow ?? 'incoming') === 'incoming';
    }

    public function isOutgoing(): bool
    {
        return $this->invoice_flow === 'outgoing';
    }

    public function flowLabel(): string
    {
        return $this->isOutgoing() ? 'صادر' : 'وارد';
    }
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
    public function vendorStore(){ return $this->belongsTo(PlumberStore::class, 'vendor_store_id'); }
    public function reviewer(){ return $this->belongsTo(User::class, 'reviewed_by'); }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(InvoiceDistribution::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(InvoiceReturn::class)->latest('id');
    }

    /** @return array{sold_qty:int, returned_qty:int, net_qty:int, sold_points:int, returned_points:int, net_points:int, returns_count:int} */
    public function returnSummary(): array
    {
        return app(\App\Services\InvoiceReturnService::class)->invoiceReturnSummary($this);
    }

    public function isFullyDistributed(): bool
    {
        $this->loadMissing('items');

        return $this->items->every(function (InvoiceItem $item) {
            $distributed = $this->distributions()
                ->where('tier', 3)
                ->where('status', '!=', 'draft')
                ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
                ->where('idi.invoice_item_id', $item->id)
                ->sum('idi.quantity');

            return $distributed >= $item->quantity;
        });
    }

    public function scopeWholesalePos($q)
    {
        return $q->where('invoice_type', 'wholesale_pos');
    }

    public function isWholesalePos(): bool
    {
        return $this->invoice_type === 'wholesale_pos';
    }

    public function getTotalItemPointsAttribute(): int
    {
        return (int) $this->items->sum('total_points');
    }

    public function scopeApprovedWholesale($query)
    {
        return $query->where('invoice_type', 'wholesale_pos')->where('status', 'approved');
    }

    public function scopeWithDistributionStats(Builder $query): Builder
    {
        return $query
            ->withSum('items as total_item_points', 'total_points')
            ->withCount('items')
            ->selectRaw('invoices.*, (
                SELECT COALESCE(SUM(idi.points_value), 0)
                FROM invoice_distribution_items idi
                INNER JOIN invoice_distributions id ON id.id = idi.distribution_id
                WHERE id.invoice_id = invoices.id
                AND id.status IN (\'confirmed\', \'points_awarded\')
            ) as distributed_points_sum');
    }

    public function distributedPointsSum(): int
    {
        if (isset($this->attributes['distributed_points_sum'])) {
            return (int) $this->attributes['distributed_points_sum'];
        }

        return (int) $this->distributions()
            ->whereIn('status', ['confirmed', 'points_awarded'])
            ->join('invoice_distribution_items as idi', 'idi.distribution_id', '=', 'invoice_distributions.id')
            ->sum('idi.points_value');
    }

    public function remainingPointsSum(): int
    {
        $total = (int) ($this->total_item_points ?? $this->items->sum('total_points'));

        return max(0, $total - $this->distributedPointsSum());
    }

    public function distributionPercent(): int
    {
        $total = (int) ($this->total_item_points ?? $this->items->sum('total_points'));

        if ($total <= 0) {
            return 0;
        }

        return (int) round(($this->distributedPointsSum() / $total) * 100);
    }

    // سكوبات
    public function scopePending($q){ return $q->where('status','pending_review'); }
    public function scopeApproved($q){ return $q->where('status','approved'); }
    public function scopeRejected($q){ return $q->where('status','rejected'); }

    // فورمات مُساعد: إجمالي بالدينار
    protected function totalDinars(): Attribute
    {
        return Attribute::get(fn() => (int) floor($this->total_cents / 100));
    }

    // يمنع السباك من تعديل الإجماليات (السباك يرفع فقط)
    public function fillByPlumber(array $data): self
    {
        // يسمح فقط بحقول الرفع: المرفق + vendor_store_id + رقم مرجعي إن أردت
        $allowed = [
            'vendor_store_id','attachment_path','number',
            // اختيارياً: subtotal_cents/tax_cents لو تحب تمنعها احذفهم
        ];
        $this->fill(collect($data)->only($allowed)->all());
        return $this;
    }

    // توليد رقم الفاتورة لو غير موجود
    public function ensureNumber(): void
    {
        if (! $this->serial_number || ! $this->number) {
            app(\App\Services\InvoiceNumberService::class)->assign($this);
        }
    }

    /**
     * اعتماد الفاتورة بواسطة الأدمن:
     * - يمنح النقاط المحددة (افتراضياً مجموع نقاط البنود)
     * - ينشئ حركة نقاط بالمحفظة للسباك
     */
    public function approveByAdmin(User $admin, int $points, ?float $profitPercent = null): void
    {
        DB::transaction(function () use ($admin, $points, $profitPercent) {
            $awarded = max(0, $points);

            $this->profit_percent = $profitPercent;
            $this->points_awarded = $awarded;
            $this->status = 'approved';
            $this->approved_at = now();
            $this->reviewed_by = $admin->id;
            $this->save();

            if (! $this->isWholesalePos() && $this->plumber_id) {
                $wallet = WalletAccount::firstOrCreate(
                    ['owner_id' => $this->plumber_id, 'currency' => $this->currency ?: 'LYD'],
                    ['balance_cents' => 0, 'balance_points' => 0]
                );
                $wallet->creditPoints($awarded, [
                    'reason' => 'invoice_approval',
                    'invoice_id' => $this->id,
                    'invoice_number' => $this->number,
                    'profit_percent' => $profitPercent,
                ], $admin);
            }
        });
    }

    public function rejectByAdmin(User $admin, array|string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
            'approved_at' => null,
            'rejection_reason' => is_array($reason) ? $reason : ['message' => (string) $reason],
            'points_awarded' => 0,
            'profit_percent' => null,
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'product_id',
        'quantity',
        'unit_price_cents',
        'points_per_unit',
        'total_points',
    ];

    protected $casts = [
        'points_per_unit' => 'decimal:4',
        'unit_price_cents' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function distributionItems(): HasMany
    {
        return $this->hasMany(InvoiceDistributionItem::class);
    }

    public function availableQuantityForTier(int $tier, ?int $parentDistributionId = null): int
    {
        if ($tier === 1) {
            $confirmed = (int) $this->distributionItems()
                ->whereHas('distribution', fn ($q) => $q
                    ->where('tier', 1)
                    ->whereIn('status', ['confirmed', 'points_awarded'])
                )
                ->sum(\Illuminate\Support\Facades\DB::raw('quantity - COALESCE(returned_quantity, 0)'));

            return max(0, (int) $this->quantity - $confirmed);
        }

        $parentItem = InvoiceDistributionItem::where('distribution_id', $parentDistributionId)
            ->where('invoice_item_id', $this->id)
            ->first();

        if (! $parentItem) {
            return 0;
        }

        $parentNet = (int) $parentItem->quantity - (int) ($parentItem->returned_quantity ?? 0);

        $alreadyConfirmed = (int) $this->distributionItems()
            ->whereHas('distribution', fn ($q) => $q
                ->where('tier', $tier)
                ->where('parent_distribution_id', $parentDistributionId)
                ->whereIn('status', ['confirmed', 'points_awarded'])
            )
            ->sum(\Illuminate\Support\Facades\DB::raw('quantity - COALESCE(returned_quantity, 0)'));

        return max(0, $parentNet - $alreadyConfirmed);
    }
}

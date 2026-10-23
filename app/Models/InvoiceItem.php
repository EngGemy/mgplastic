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
        'box_count',
        'pieces_per_box',
        'unit_price_cents',
        'points_per_unit',
        'total_points',
    ];

    protected $casts = [
        'box_count' => 'integer',
        'pieces_per_box' => 'integer',
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

    public function availableQuantityForTier(
        int $tier,
        ?int $parentDistributionId = null,
        ?int $exceptDistributionId = null,
    ): int {
        if ($tier === 1) {
            // draft = reserved awaiting buyer receipt — must not be double-sold.
            $reserved = (int) $this->distributionItems()
                ->when($exceptDistributionId, fn ($q) => $q->where('distribution_id', '!=', $exceptDistributionId))
                ->whereHas('distribution', fn ($q) => $q
                    ->where('tier', 1)
                    ->whereIn('status', ['draft', 'confirmed', 'points_awarded'])
                )
                ->sum(\Illuminate\Support\Facades\DB::raw('quantity - COALESCE(returned_quantity, 0)'));

            return max(0, (int) $this->quantity - $reserved);
        }

        $parentItem = InvoiceDistributionItem::where('distribution_id', $parentDistributionId)
            ->where('invoice_item_id', $this->id)
            ->first();

        if (! $parentItem) {
            return 0;
        }

        $parentNet = (int) $parentItem->quantity - (int) ($parentItem->returned_quantity ?? 0);

        $alreadyReserved = (int) $this->distributionItems()
            ->when($exceptDistributionId, fn ($q) => $q->where('distribution_id', '!=', $exceptDistributionId))
            ->whereHas('distribution', fn ($q) => $q
                ->where('tier', $tier)
                ->where('parent_distribution_id', $parentDistributionId)
                ->whereIn('status', ['draft', 'confirmed', 'points_awarded'])
            )
            ->sum(\Illuminate\Support\Facades\DB::raw('quantity - COALESCE(returned_quantity, 0)'));

        return max(0, $parentNet - $alreadyReserved);
    }
}

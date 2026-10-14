<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceDistributionItem extends Model
{
    protected $fillable = [
        'distribution_id',
        'invoice_item_id',
        'quantity',
        'returned_quantity',
        'points_value',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'returned_quantity' => 'integer',
        'points_value' => 'integer',
    ];

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(InvoiceDistribution::class, 'distribution_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function netQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }

    public function netPoints(): int
    {
        return self::pointsFor((int) $this->quantity, (int) $this->returned_quantity, (int) $this->points_value);
    }

    public static function pointsFor(int $quantity, int $returned, int $points): int
    {
        $net = max(0, $quantity - $returned);

        if ($quantity <= 0 || $net <= 0) {
            return 0;
        }

        return (int) floor($points * $net / $quantity);
    }
}

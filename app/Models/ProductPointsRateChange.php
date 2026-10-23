<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPointsRateChange extends Model
{
    protected $fillable = [
        'product_id',
        'changed_by',
        'old_points_per_unit',
        'new_points_per_unit',
        'delta_points_per_unit',
        'change_percent',
        'wholesale_remaining_qty',
        'retail_remaining_qty',
        'wholesale_holders',
        'retail_holders',
        'estimated_points_delta',
        'note',
        'impact_snapshot',
    ];

    protected $casts = [
        'old_points_per_unit' => 'decimal:4',
        'new_points_per_unit' => 'decimal:4',
        'delta_points_per_unit' => 'decimal:4',
        'change_percent' => 'decimal:2',
        'wholesale_remaining_qty' => 'integer',
        'retail_remaining_qty' => 'integer',
        'wholesale_holders' => 'integer',
        'retail_holders' => 'integer',
        'estimated_points_delta' => 'integer',
        'impact_snapshot' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function direction(): string
    {
        $delta = (float) $this->delta_points_per_unit;

        return $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
    }
}

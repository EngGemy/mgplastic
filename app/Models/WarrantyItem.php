<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'warranty_id', 'product_id', 'distribution_id', 'invoice_item_id', 'quantity', 'warranty_months',
    ];

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(InvoiceDistribution::class, 'distribution_id');
    }
}

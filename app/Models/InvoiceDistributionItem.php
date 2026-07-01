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
        'points_value',
    ];

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(InvoiceDistribution::class, 'distribution_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}

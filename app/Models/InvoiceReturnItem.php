<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceReturnItem extends Model
{
    protected $fillable = [
        'invoice_return_id',
        'invoice_item_id',
        'distribution_item_id',
        'product_id',
        'quantity',
        'points_value',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'points_value' => 'integer',
        ];
    }

    public function invoiceReturn(): BelongsTo
    {
        return $this->belongsTo(InvoiceReturn::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function distributionItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceDistributionItem::class, 'distribution_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

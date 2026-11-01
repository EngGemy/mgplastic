<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrAlias extends Model
{
    protected $fillable = [
        'normalized_text',
        'product_id',
        'hits',
        'last_used_at',
    ];

    protected $casts = [
        'hits' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

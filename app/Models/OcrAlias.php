<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrAlias extends Model
{
    protected $fillable = [
        'normalized_text',
        'system_hint',
        'product_id',
        'customer_user_id',
        'owner_user_id',
        'qty_unit',
        'scope',
        'confirming_users',
        'hits',
        'last_used_at',
    ];

    protected $casts = [
        'hits' => 'integer',
        'confirming_users' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }
}

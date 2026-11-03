<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrImageLine extends Model
{
    protected $fillable = [
        'image_id',
        'line_no',
        'raw_fragment',
        'resolved_product_id',
        'qty',
        'review_status',
        'version',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'payload' => 'array',
        ];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(OcrImage::class, 'image_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'resolved_product_id');
    }
}

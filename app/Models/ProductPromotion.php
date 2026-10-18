<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductPromotion extends Model
{
    protected $fillable = [
        'title',
        'subtitle',
        'body',
        'media_type',
        'image_path',
        'comparison_image_path',
        'video_url',
        'product_id',
        'product_url',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function comparisonImageUrl(): ?string
    {
        return $this->comparison_image_path
            ? Storage::disk('public')->url($this->comparison_image_path)
            : null;
    }

    public function resolveProductLink(): ?string
    {
        if (filled($this->product_url)) {
            return $this->product_url;
        }

        if ($this->product_id) {
            return url('/products/'.$this->product_id);
        }

        return null;
    }
}

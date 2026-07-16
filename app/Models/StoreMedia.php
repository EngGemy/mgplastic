<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class StoreMedia extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (self $media) {
            if ($media->file_path) {
                Storage::disk('public')->delete($media->file_path);
            }
            if ($media->thumbnail_path) {
                Storage::disk('public')->delete($media->thumbnail_path);
            }
        });
    }
    protected $fillable = [
        'owner_type',
        'owner_id',
        'kind',
        'product_id',
        'file_path',
        'thumbnail_path',
        'title',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = ['url', 'thumbnail_url', 'media_type'];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->file_path
            ? Storage::disk('public')->url($this->file_path)
            : null;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if ($this->thumbnail_path) {
            return Storage::disk('public')->url($this->thumbnail_path);
        }

        if ($this->kind !== 'video' && $this->file_path) {
            return $this->url;
        }

        return null;
    }

    public function getMediaTypeAttribute(): string
    {
        return $this->kind === 'video' ? 'video' : 'image';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'type' => $this->media_type,
            'name' => $this->title,
            'title' => $this->title,
            'description' => $this->description,
            'url' => $this->url,
            'image_url' => $this->url,
            'thumbnail' => $this->thumbnail_url,
            'product_id' => $this->product_id,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
        ] + ($this->kind === 'my_product' ? ['has_points' => false] : []);
    }

    public function scopeMyProducts($query)
    {
        return $query->where('kind', 'my_product')->where('is_active', true)->orderBy('sort_order');
    }
}

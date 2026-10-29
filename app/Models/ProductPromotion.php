<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'video_path',
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
        return PublicStorageUrl::try($this->image_path);
    }

    public function comparisonImageUrl(): ?string
    {
        return PublicStorageUrl::try($this->comparison_image_path);
    }

    /** Uploaded file on disk wins over external URL. */
    public function resolvedVideoUrl(): ?string
    {
        if (filled($this->video_path)) {
            return PublicStorageUrl::try($this->video_path);
        }

        $url = trim((string) ($this->video_url ?? ''));

        return $url !== '' ? $url : null;
    }

    /** @return 'file'|'external'|null */
    public function videoSource(): ?string
    {
        if (filled($this->video_path)) {
            return 'file';
        }

        return filled($this->video_url) ? 'external' : null;
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

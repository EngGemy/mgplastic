<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PlumberWorkPhoto extends Model
{
    protected $table = 'plumber_work_photos';

    protected $fillable = [
        'plumber_id',
        'image',
        'thumbnail_path',
        'video_path',
    ];

    protected $casts = [
        'plumber_id' => 'integer',
    ];

    protected $appends = [
        'image_url',
        'video_url',
        'type',
        'is_video',
        'thumbnail_url',
        'path',
        'url',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $photo) {
            foreach ([$photo->image, $photo->thumbnail_path, $photo->video_path] as $file) {
                if (filled($file) && Storage::disk('public')->exists($file)) {
                    Storage::disk('public')->delete($file);
                }
            }
        });
    }

    public function plumber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plumber_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        return PublicStorageUrl::try($this->image);
    }

    public function getVideoUrlAttribute(): ?string
    {
        return PublicStorageUrl::try($this->video_path);
    }

    public function getTypeAttribute(): string
    {
        if (! empty($this->video_path)) {
            return 'video';
        }
        if (! empty($this->image)) {
            return 'image';
        }

        return 'unknown';
    }

    public function getIsVideoAttribute(): bool
    {
        return ! empty($this->video_path);
    }

    /**
     * Prefer dedicated thumbnail; videos fall back to cover in `image`; images fall back to full image.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        return PublicStorageUrl::try($this->thumbnail_path)
            ?? $this->image_url
            ?? $this->video_url;
    }

    public function getPathAttribute(): ?string
    {
        return $this->is_video ? $this->video_path : $this->image;
    }

    public function getUrlAttribute(): ?string
    {
        return $this->is_video ? $this->video_url : $this->image_url;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'path' => $this->path,
            'url' => $this->url,
            'thumbnail' => $this->thumbnail_url,
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}

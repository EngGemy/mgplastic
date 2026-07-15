<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PlumberWorkPhoto extends Model
{
    protected $table = 'plumber_work_photos';

    protected $fillable = [
        'plumber_id',
        'image',       // image path (public disk)
        'video_path',  // video path (public disk) — requires migration
    ];

    protected $casts = [
        'plumber_id' => 'integer',
    ];

    /**
     * Always include helpful URLs / meta in JSON.
     */
    protected $appends = [
        'image_url',
        'video_url',
        'type',          // 'image' | 'video' | 'unknown'
        'is_video',
        'thumbnail_url', // video: generated cover, image: itself
        'path',          // primary media path (video_path for videos)
        'url',           // public URL matching path
    ];

    /** Relations */
    public function plumber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plumber_id');
    }

    /** Accessors */
    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image)) {
            return null;
        }
        return Storage::disk('public')->url($this->image);
    }

    public function getVideoUrlAttribute(): ?string
    {
        if (empty($this->video_path)) {
            return null;
        }
        return Storage::disk('public')->url($this->video_path);
    }

    public function getTypeAttribute(): string
    {
        // Videos store their generated cover in `image`, so check video first.
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

    /** Cover image: the video thumbnail (stored in `image`) or the image itself. */
    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->image_url ?? $this->video_url;
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
     * Unified shape shared by the mobile API and admin panel.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'path'       => $this->path,
            'url'        => $this->url,
            'thumbnail'  => $this->thumbnail_url,
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}

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
        'type',   // 'image' | 'video' | 'unknown'
        'path',   // image or video_path
        'url',    // public URL matching path
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
        if (! empty($this->image)) {
            return 'image';
        }
        if (! empty($this->video_path)) {
            return 'video';
        }
        return 'unknown';
    }

    public function getPathAttribute(): ?string
    {
        return $this->image ?: $this->video_path;
    }

    public function getUrlAttribute(): ?string
    {
        return $this->image_url ?? $this->video_url;
    }
}

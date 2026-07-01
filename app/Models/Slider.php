<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Slider extends Model
{
    protected $fillable = [
        'image', 'type', 'tag', 'title', 'description',
        'cta_primary_text', 'cta_primary_url',
        'cta_secondary_text', 'cta_secondary_url',
        'background_style', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['image_url'];

    protected static function booted(): void
    {
        static::deleting(function (self $slider) {
            if ($slider->image) {
                Storage::disk('public')->delete($slider->image);
            }
        });
    }

    /** Accessor for full URL */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForHome($query)
    {
        return $query->where('type', 'home');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function backgroundCss(): string
    {
        if ($this->image) {
            return "background-image:url('{$this->image_url}');background-size:cover;background-position:center";
        }

        return $this->background_style
            ?: 'background:linear-gradient(135deg,#0d2d6e 0%,#1a56db 100%)';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $fillable = [
        'name_ar', 'role_key', 'role_label_ar', 'city_ar', 'body_ar', 'rating',
        'product_hint', 'avatar_initials', 'source', 'is_active', 'is_featured',
        'sort_order', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where(function ($q) {
            $q->whereNull('published_at')->orWhere('published_at', '<=', now());
        });
    }

    public function scopeForPublic(Builder $q): Builder
    {
        return $q->active()->published()
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->orderBy('sort_order');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name_ar,
            'role' => $this->role_label_ar,
            'role_key' => $this->role_key,
            'city' => $this->city_ar,
            'body' => $this->body_ar,
            'rating' => (int) $this->rating,
            'product_hint' => $this->product_hint,
            'avatar_initials' => $this->avatar_initials ?: $this->initials(),
            'is_featured' => (bool) $this->is_featured,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name_ar)) ?: [];
        $letters = collect($parts)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');

        return mb_strtoupper($letters ?: mb_substr($this->name_ar, 0, 2));
    }
}

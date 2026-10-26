<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HomeProductType extends Model
{
    protected $fillable = [
        'key',
        'name',
        'subtitle',
        'icon',
        'image',
        'keywords',
        'exclude_keywords',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'exclude_keywords' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function imageUrl(): ?string
    {
        return PublicStorageUrl::try($this->image);
    }

    /** @return array<string, mixed> */
    public function toDefinition(): array
    {
        return [
            'key' => (string) $this->key,
            'name' => (string) $this->name,
            'subtitle' => (string) ($this->subtitle ?? ''),
            'icon' => (string) ($this->icon ?: $this->key),
            'image' => $this->image ? ltrim((string) $this->image, '/') : null,
            'image_url' => $this->imageUrl(),
            'keywords' => array_values(array_filter(array_map('strval', $this->keywords ?? []))),
            'exclude_keywords' => array_values(array_filter(array_map('strval', $this->exclude_keywords ?? []))),
        ];
    }
}

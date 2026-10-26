<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HomeHighlight extends Model
{
    protected $fillable = [
        'key',
        'label',
        'icon',
        'link',
        'deep_link',
        'category_match',
        'category_prefer',
        'category_exclude',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category_match' => 'array',
            'category_prefer' => 'array',
            'category_exclude' => 'array',
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

    /** @return array<string, mixed> */
    public function toConfigRow(): array
    {
        return [
            'key' => (string) $this->key,
            'label' => (string) $this->label,
            'icon' => (string) ($this->icon ?: $this->key),
            'link' => $this->link,
            'deep_link' => $this->deep_link,
            'category_match' => array_values(array_filter(array_map('strval', $this->category_match ?? []))),
            'category_prefer' => array_values(array_filter(array_map('strval', $this->category_prefer ?? []))),
            'category_exclude' => array_values(array_filter(array_map('strval', $this->category_exclude ?? []))),
        ];
    }
}

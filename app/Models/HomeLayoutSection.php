<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HomeLayoutSection extends Model
{
    protected $fillable = [
        'key',
        'type',
        'title',
        'is_active',
        'sort_order',
        'sort_orders',
        'roles',
        'limit',
        'see_all',
        'settings',
        'items_override',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'sort_orders' => 'array',
            'limit' => 'integer',
            'roles' => 'array',
            'see_all' => 'array',
            'settings' => 'array',
            'items_override' => 'array',
        ];
    }

    public function sortForRole(string $role): int
    {
        $orders = is_array($this->sort_orders) ? $this->sort_orders : [];

        if (array_key_exists($role, $orders) && is_numeric($orders[$role])) {
            return (int) $orders[$role];
        }

        return (int) $this->sort_order;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function scopeForRole(Builder $query, string $role): Builder
    {
        return $query->whereJsonContains('roles', $role);
    }

    public function typeLabel(): string
    {
        return (string) (config('home_layout.types.'.$this->type.'.label') ?? $this->type);
    }

    public function source(): string
    {
        return (string) (config('home_layout.types.'.$this->type.'.source') ?? 'api');
    }
}

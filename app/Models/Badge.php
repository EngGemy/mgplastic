<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Badge extends Model
{
    protected $fillable = [
        'key', 'name_ar', 'name_en', 'description_ar', 'icon', 'tier', 'rule', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'rule' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_badges')
            ->withPivot(['awarded_at', 'source_type', 'source_id']);
    }
}

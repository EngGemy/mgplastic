<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteStat extends Model
{
    protected $fillable = [
        'value', 'suffix', 'label_ar', 'label_en', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'value' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}

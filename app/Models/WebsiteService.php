<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteService extends Model
{
    protected $fillable = [
        'icon', 'title_ar', 'subtitle_en', 'description_ar', 'sort_order', 'is_active',
    ];

    protected $casts = [
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

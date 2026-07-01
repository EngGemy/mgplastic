<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Size extends Model
{
    protected $fillable = ['size_system_id','code','label_en','label_ar','sort','meta'];
    protected $casts = ['meta' => 'array'];

    public function system(): BelongsTo
    {
        return $this->belongsTo(SizeSystem::class, 'size_system_id');
    }

    // direct pivot model (if you want to touch extra fields)
    public function productSizes(): HasMany
    {
        return $this->hasMany(ProductSize::class);
    }

    // many-to-many shortcut
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_sizes')->withTimestamps()->withPivot('meta');
    }
}

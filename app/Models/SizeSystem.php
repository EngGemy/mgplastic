<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SizeSystem extends Model
{
    protected $fillable = ['code','name_en','name_ar'];

    public function sizes(): HasMany
    {
        return $this->hasMany(Size::class);
    }
}

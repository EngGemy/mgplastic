<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStandard extends Model
{
    protected $fillable = ['code','name_en','name_ar'];
    public function getNameAttribute(): ?string
    {
        $locale = app()->getLocale();
        return $locale === 'ar' ? ($this->name_ar ?? $this->name_en) : ($this->name_en ?? $this->name_ar);
    }
}

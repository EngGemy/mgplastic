<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $fillable = ['country_id', 'name_en', 'name_ar'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}

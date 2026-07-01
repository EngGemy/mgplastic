<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;

class Event extends Model
{
    use Translatable;

    public $translatedAttributes = ['title', 'description'];

    protected $fillable = [
        'category_id',
        'city_id',
        'image',
        'event_date',
        'event_time',
        'address',         // ← added

        'latitude',
        'longitude',
    ];

    public function category()
    {
        return $this->belongsTo(EventCategory::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}


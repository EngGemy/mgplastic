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
        'location_city',
        'location_country',
        'image',
        'event_date',
        'event_time',
        'address',
        'latitude',
        'longitude',
    ];

    /** Display city for API/admin — free text first, then legacy city relation. */
    public function displayCityName(?string $lang = 'ar'): ?string
    {
        if (filled($this->location_city)) {
            return (string) $this->location_city;
        }

        if ($this->relationLoaded('city') && $this->city) {
            return $lang === 'ar'
                ? ($this->city->name_ar ?: $this->city->name_en)
                : ($this->city->name_en ?: $this->city->name_ar);
        }

        return null;
    }

    public function displayCountryName(?string $lang = 'ar'): ?string
    {
        if (filled($this->location_country)) {
            return (string) $this->location_country;
        }

        $country = $this->relationLoaded('city') && $this->city
            ? ($this->city->relationLoaded('country') ? $this->city->country : $this->city->country()->first())
            : null;

        if ($country) {
            return $lang === 'ar'
                ? ($country->name_ar ?: $country->name_en)
                : ($country->name_en ?: $country->name_ar);
        }

        return null;
    }

    public function category()
    {
        return $this->belongsTo(EventCategory::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}


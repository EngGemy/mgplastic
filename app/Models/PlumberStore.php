<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use App\Models\Concerns\HasStoreProfile;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;

class PlumberStore extends Model implements TranslatableContract
{
    use HasStoreProfile, Translatable;

    protected $table = 'plumber_stores';

    public $translatedAttributes = ['name', 'description'];
    protected $translationModel = PlumberStoreTranslation::class;
    public $useTranslationFallback = true;

    protected $fillable = [
        'vendor_id',        // NEW: owner vendor
        'city_id',
        'address',
        'available_date',
        'available_time',
        'phone',
        'image',
        'latitude',
        'longitude',
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    public function vendor()
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }



    public function images()
    {
        return $this->hasMany(PlumberStoreImage::class, 'plumber_store_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    // Optional, unrelated to ownership
    /**
     * CHANGED: array of nearest plumbers (same city).
     */
    public function nearestPlumbers()
    {
        return $this->hasMany(User::class, 'city_id', 'city_id')
            ->where('role', User::ROLE_PLUMBER)
            ->latest('id');
    }
}

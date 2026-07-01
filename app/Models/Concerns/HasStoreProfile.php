<?php

namespace App\Models\Concerns;

use App\Models\SocialLink;
use App\Models\StoreMedia;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasStoreProfile
{
    public function storeMedia(): MorphMany
    {
        return $this->morphMany(StoreMedia::class, 'owner')
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }

    public function socialLinks(): MorphMany
    {
        return $this->morphMany(SocialLink::class, 'linkable')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function banners()
    {
        return $this->storeMedia()->where('kind', 'banner')->where('is_active', true);
    }

    public function storeVideos()
    {
        return $this->storeMedia()->where('kind', 'video')->where('is_active', true);
    }

    public function productShowcaseImages()
    {
        return $this->storeMedia()->where('kind', 'product_image')->where('is_active', true);
    }
}

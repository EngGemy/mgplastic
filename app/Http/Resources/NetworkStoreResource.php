<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkStoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $this->whenLoaded('storeMedia', fn () => $this->storeMedia, collect());
        $social = $this->whenLoaded('socialLinks', fn () => $this->socialLinks, collect());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'role' => $this->role,
            'role_label' => match ($this->role) {
                'wholesale_distributor' => 'موزّع جملة',
                'retail_trader' => 'تاجر تجزئة',
                default => $this->role,
            },
            'address' => $this->address,
            'store_description' => $this->store_description,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'about_me' => $this->about_me,
            'profile_photo_url' => $this->profile_photo_url,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'map_url' => $this->mapUrl(),
            'city' => $this->whenLoaded('city', fn () => $this->city ? [
                'id' => $this->city->id,
                'name_ar' => $this->city->name_ar ?? null,
                'name_en' => $this->city->name_en ?? null,
            ] : null),
            'country' => $this->whenLoaded('country', fn () => $this->country ? [
                'id' => $this->country->id,
                'name_ar' => $this->country->name_ar ?? null,
                'name_en' => $this->country->name_en ?? null,
            ] : null),
            'banners' => $media->where('kind', 'banner')->values()->map->toApiArray(),
            'videos' => $media->where('kind', 'video')->values()->map->toApiArray(),
            'product_images' => $media->where('kind', 'product_image')->values()->map(fn ($item) => array_merge(
                $item->toApiArray(),
                ['product_name' => $item->product?->name ?? null],
            )),
            'gallery' => $media->where('kind', 'gallery')->values()->map->toApiArray(),
            'social_links' => $social->map->toApiArray(),
        ];
    }
}

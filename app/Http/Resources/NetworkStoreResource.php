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

        $showSocial = (bool) ($this->show_social_links ?? true);
        $storeStatus = $this->networkStoreStatus();

        return [
            'id' => $this->id,
            'network_code' => $this->network_code,
            'name' => $this->name,
            'brand_name' => $this->brand_name,
            'phone' => $this->phone,
            'role' => $this->role,
            'role_label' => match ($this->role) {
                'wholesale_distributor' => 'موزّع جملة',
                'retail_trader' => 'تاجر تجزئة',
                default => $this->role,
            },
            'is_approved' => (bool) $this->is_approved,
            'is_active' => (bool) $this->is_active,
            'status_code' => $storeStatus['code'],
            'status_message' => $storeStatus['message'],
            'is_public' => $storeStatus['is_public'],
            'address' => $this->address,
            'store_description' => $this->store_description,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'about_me' => $this->about_me,
            'website' => $this->website,
            'profile_photo_url' => $this->profile_photo_url,
            'brand_logo_url' => $this->brand_logo ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->brand_logo) : null,
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
            'slider' => $media->where('kind', 'banner')->values()->map->toApiArray(),
            'videos' => $media->where('kind', 'video')->values()->map->toApiArray(),
            'product_images' => $media->where('kind', 'product_image')->values()->map(fn ($item) => array_merge(
                $item->toApiArray(),
                ['product_name' => $item->product?->name ?? null],
            )),
            'gallery' => $media->where('kind', 'gallery')->values()->map->toApiArray(),
            'show_social_links' => $showSocial,
            'social_links' => $showSocial ? $social->map->toApiArray() : [],
        ];
    }
}

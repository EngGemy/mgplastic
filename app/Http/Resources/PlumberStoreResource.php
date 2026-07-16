<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class PlumberStoreResource extends JsonResource
{
    public function toArray($request)
    {
        $accept = (string) $request->header('Accept-Language', 'en');
        $accept = str_replace('_', '-', strtolower($accept));
        $locale = substr($accept, 0, 2);
        if (! in_array($locale, ['ar','en'], true)) {
            $locale = 'en';
        }

        $cityCol = $locale === 'ar' ? 'name_ar' : 'name_en';

        $tr = $this->translate($locale)
            ?? $this->translate('en')
            ?? ($this->translations->first() ?? null);

        $name        = $tr->name ?? null;
        $description = $tr->description ?? null;

        return [
            'id'             => $this->id,
            'name'           => $name,
            'description'    => $description,
            'address'        => $this->address,
            'phone'          => $this->phone,
            'image'          => $this->image,
            'image_url'      => $this->image_url,

            // slider images (if relation loaded)
            'slider' => $this->when(
                $this->relationLoaded('images'),
                $this->images->map(fn($img) => [
                    'id'         => $img->id,
                    'url'        => $img->url,
                    'caption'    => $img->caption,
                    'sort_order' => $img->sort_order,
                ])
            ),

            'banners' => $this->when(
                $this->relationLoaded('storeMedia'),
                $this->storeMedia->where('kind', 'banner')->values()->map->toApiArray()
            ),

            'videos' => $this->when(
                $this->relationLoaded('storeMedia'),
                $this->storeMedia->where('kind', 'video')->values()->map->toApiArray()
            ),

            'product_images' => $this->when(
                $this->relationLoaded('storeMedia'),
                $this->storeMedia->where('kind', 'product_image')->values()->map->toApiArray()
            ),

            // معرض «منتجاتي» — من ميديا المتجر + حساب الشبكة المرتبط (موزّع/تاجر)
            'my_products' => $this->resolveMyProducts()->map->toApiArray()->values(),

            'social_links' => $this->when(
                $this->relationLoaded('socialLinks'),
                $this->socialLinks->map->toApiArray()
            ),

            'available_date' => $this->available_date,
            'available_time' => $this->available_time,
            'latitude'       => $this->latitude,
            'longitude'      => $this->longitude,

            'city' => $this->when(
                $this->relationLoaded('city') && $this->city,
                [
                    'id'   => $this->city->id,
                    'name' => $this->city->{$cityCol} ?? null,
                ]
            ),

            'vendor' => $this->when(
                $this->relationLoaded('vendor') && $this->vendor,
                [
                    'id'                => $this->vendor->id,
                    'name'              => $this->vendor->name,
                    'phone'             => $this->vendor->phone,
                    'email'             => $this->vendor->email,
                    'profile_photo_url' => $this->vendor->profile_photo_url,
                ]
            ),

            // CHANGED: array with image & description for each plumber
            'nearest_plumbers' => $this->when(
                $this->relationLoaded('nearestPlumbers'),
                $this->nearestPlumbers->map(fn($u) => [
                    'id'                 => $u->id,
                    'name'               => $u->name,
                    'phone'              => $u->phone,
                    'image_url'          => $u->profile_photo_url ?? null, // common Jetstream accessor
                    'description'        => $u->description ?? $u->bio ?? null, // optional field
                ])
            ),
        ];
    }

    /**
     * My Products live on network store Users (Filament «منتجاتي»).
     * Legacy plumber_stores often share the same phone — merge both sources.
     */
    protected function resolveMyProducts(): Collection
    {
        $fromStore = $this->relationLoaded('storeMedia')
            ? $this->storeMedia->where('kind', 'my_product')->where('is_active', true)
            : collect();

        $networkOwner = $this->resolveNetworkStoreOwner();
        $fromNetwork = $networkOwner
            ? $networkOwner->storeMedia()
                ->where('kind', 'my_product')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
            : collect();

        return $fromStore
            ->concat($fromNetwork)
            ->unique('id')
            ->sortBy('sort_order')
            ->values();
    }

    protected function resolveNetworkStoreOwner(): ?User
    {
        $vendor = $this->relationLoaded('vendor') ? $this->vendor : $this->vendor()->first();

        if ($vendor instanceof User && $vendor->isNetworkStore()) {
            return $vendor;
        }

        $phones = collect([
            $this->phone,
            $vendor?->phone,
        ])->filter()->map(fn ($p) => trim((string) $p))->filter()->unique()->values();

        if ($phones->isEmpty()) {
            return null;
        }

        return User::query()
            ->where(function ($q) {
                $q->where('role', 'wholesale_distributor')
                    ->orWhere('role', 'retail_trader');
            })
            ->whereIn('phone', $phones->all())
            ->where('is_approved', true)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }
}

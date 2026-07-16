<?php

namespace App\Http\Resources\Mobile;

use App\Support\UserRoles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'role_label' => UserRoles::label($this->role),
            'profile_photo' => $this->profile_photo_url,
            'profile_photo_path' => $this->profile_photo,
            'about_me' => $this->about_me,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'video_url' => $this->video_url,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'country_id' => $this->country_id,
            'city_id' => $this->city_id,
            'country' => $this->whenLoaded('country', fn () => [
                'id' => $this->country?->id,
                'name' => $this->country?->{app()->getLocale() === 'ar' ? 'name_ar' : 'name_en'},
            ]),
            'city' => $this->whenLoaded('city', fn () => [
                'id' => $this->city?->id,
                'name' => $this->city?->{app()->getLocale() === 'ar' ? 'name_ar' : 'name_en'},
            ]),
            'network_code' => $this->network_code,
            'parent_distributor_id' => $this->parent_distributor_id,
            'is_phone_verified' => (bool) $this->is_phone_verified,
            'is_approved' => (bool) $this->is_approved,
            'is_active' => (bool) $this->is_active,
            'store_description' => $this->store_description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

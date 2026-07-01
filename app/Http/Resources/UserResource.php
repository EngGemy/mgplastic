<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => new CountryResource($this->whenLoaded('country')),  // Include country resource if loaded
            'city' => new CityResource($this->whenLoaded('city')),  // Include city resource if loaded
            'profile_photo' => $this->profile_photo,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
        ];
    }
}

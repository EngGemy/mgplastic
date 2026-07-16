<?php

namespace App\Http\Resources\Plumber;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;

class PlumberListResource extends JsonResource
{
    public function toArray($request)
    {
        $locale  = $this->resolveLocale($request, ['en','ar']);
        $nameCol = $locale === 'ar' ? 'name_ar' : 'name_en';

        // Safe locals
        $city    = $this->city;     // may be null
        $country = $this->country;  // may be null

        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'profile_photo_url' => $this->profile_photo_url,
            'short_description' => $this->short_description,

            'latitude'  => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'lat'       => $this->latitude !== null ? (float) $this->latitude : null,
            'long'      => $this->longitude !== null ? (float) $this->longitude : null,

            // Never touch ->id unless the relation exists
            'city' => $city ? [
                'id'   => $city->id,
                'name' => $city->{$nameCol} ?? null,
            ] : null,

            'country' => $country ? [
                'id'   => $country->id,
                'name' => $country->{$nameCol} ?? null,
            ] : null,

            'accepted_language' => $locale,
        ];
    }

    private function resolveLocale(Request $request, array $supported): string
    {
        $raw   = $request->header('Accept-Language') ?? $request->header('X-Locale') ?? $request->query('lang', 'en');
        $first = strtolower(preg_split('/[,;]/', $raw)[0] ?? 'en');
        $base  = explode('-', $first)[0];
        return in_array($base, $supported, true) ? $base : 'en';
    }
}

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

        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'profile_photo_url' => $this->profile_photo_url,
            'short_description' => $this->short_description,

            // 👇 wrap in closures so props are only read when relation exists
            'city' => $this->when(
                $this->relationLoaded('city') && $this->city,
                fn () => [
                    'id'   => optional($this->city)->id,
                    'name' => optional($this->city)->{$nameCol},
                ]
            ),

            'country' => $this->when(
                $this->relationLoaded('country') && $this->country,
                fn () => [
                    'id'   => optional($this->country)->id,
                    'name' => optional($this->country)->{$nameCol},
                ]
            ),

            'accepted_language' => $locale,
        ];
    }

    private function resolveLocale(Request $request, array $supported): string
    {
        $raw = $request->header('Accept-Language')
            ?? $request->header('X-Locale')
            ?? $request->query('lang', 'en');

        $first = strtolower(preg_split('/[,;]/', $raw)[0] ?? 'en');
        $base  = explode('-', $first)[0];

        return in_array($base, $supported, true) ? $base : 'en';
    }
}

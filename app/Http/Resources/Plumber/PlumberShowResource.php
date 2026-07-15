<?php

namespace App\Http\Resources\Plumber;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;

class PlumberShowResource extends JsonResource
{
    public function toArray($request)
    {
        $locale  = $this->resolveLocale($request, ['en','ar']);
        $nameCol = $locale === 'ar' ? 'name_ar' : 'name_en';

        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'phone'             => $this->phone,
            'profile_photo_url' => $this->profile_photo_url,
            'about_me'          => $this->about_me,
            'short_description' => $this->short_description,
            'long_description'  => $this->long_description,
            'video_url'         => $this->video_url,

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

            // Legacy key (images only) kept for backward compatibility.
            'work_photos' => $this->when(
                $this->relationLoaded('workPhotos'),
                fn () => $this->workPhotos
                    ->reject(fn ($p) => $p->is_video)
                    ->map(fn ($p) => [
                        'id'        => $p->id,
                        'image'     => $p->image,
                        'image_url' => $p->image_url,
                        'created_at'=> optional($p->created_at)->toISOString(),
                    ])
                    ->values()
            ),

            // Unified media (images + videos with auto thumbnails).
            'work_media' => $this->when(
                $this->relationLoaded('workPhotos'),
                fn () => $this->workPhotos->map(fn ($p) => $p->toApiArray())->values()
            ),

            'work_media_count' => $this->when(
                $this->relationLoaded('workPhotos'),
                fn () => $this->workPhotos->count()
            ),

            'work_images_count' => $this->when(
                $this->relationLoaded('workPhotos'),
                fn () => $this->workPhotos->reject(fn ($p) => $p->is_video)->count()
            ),

            'work_videos_count' => $this->when(
                $this->relationLoaded('workPhotos'),
                fn () => $this->workPhotos->filter(fn ($p) => $p->is_video)->count()
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

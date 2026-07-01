<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EventResource extends JsonResource
{
    public function toArray($request)
    {
        // Accept-Language → ar/en (default en)
        $raw  = (string) $request->header('Accept-Language', 'en');
        $lang = substr(strtolower(str_replace('_', '-', $raw)), 0, 2);
        if (!in_array($lang, ['ar', 'en'], true)) $lang = 'en';

        // Localize title & description
        $title = null; $desc = null;
        if (method_exists($this, 'translate')) {
            $tr = $this->translate($lang) ?? $this->translate('en');
            $title = $tr->title ?? null;
            $desc  = $tr->description ?? null;
        }
        if ($title === null || $desc === null) {
            $titleCol = $lang === 'ar' ? 'title_ar' : 'title_en';
            $descCol  = $lang === 'ar' ? 'description_ar' : 'description_en';
            $title = $title ?? ($this->{$titleCol} ?? $this->title_en ?? $this->title ?? null);
            $desc  = $desc  ?? ($this->{$descCol}  ?? $this->description_en ?? $this->description ?? null);
        }

        // Localize category & city names (flat keys as requested)
        $categoryName = null;
        if ($this->relationLoaded('category') && $this->category) {
            $categoryName = $lang === 'ar'
                ? ($this->category->name_ar ?? null)
                : ($this->category->name_en ?? null);
        }

        $cityName = null;
        if ($this->relationLoaded('city') && $this->city) {
            $cityName = $lang === 'ar'
                ? ($this->city->name_ar ?? null)
                : ($this->city->name_en ?? null);
        }

        return [
            'id'                => $this->id,
            'title'             => $title,
            'description'       => $desc,

            'image'             => $this->image,
            'image_url'         => $this->image ? Storage::disk('public')->url($this->image) : null,

            'event_date'        => $this->event_date,
            'event_time'        => $this->event_time,

            'address'           => $this->address,         // ← string "address"
            'latitude'          => $this->latitude,
            'longitude'         => $this->longitude,

            'category_id'       => $this->category_id,
            'category_name'     => $categoryName,          // ← not name_en
            'city_id'           => $this->city_id,
            'city_name'         => $cityName,              // ← not name_en

            'created_at'        => optional($this->created_at)->toISOString(),
            'updated_at'        => optional($this->updated_at)->toISOString(),
        ];
    }}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SocialMediaResource extends JsonResource
{
    public function toArray($request)
    {
        // Resolve from Accept-Language (e.g., "ar-EG" -> "ar")
        $raw   = (string) $request->header('Accept-Language', 'en');
        $lang  = substr(strtolower(str_replace('_', '-', $raw)), 0, 2);
        if (! in_array($lang, ['ar','en'], true)) {
            $lang = 'en';
        }

        // Localize `name` (Astrotomic Translatable -> column per lang -> plain)
        $name = null;

        if (method_exists($this, 'translate')) {
            $tr = $this->translate($lang) ?? $this->translate('en');
            if ($tr) {
                $name = $tr->name ?? $name;
            }
        }

        if ($name === null) {
            $nameCol = $lang === 'ar' ? 'name_ar' : 'name_en';
            $name = $this->{$nameCol} ?? $this->name_en ?? $this->name ?? null;
        }

        return [
            'id'                => $this->id,
            'name'              => $name,
            'url'               => $this->url,
            'platform'          => $this->platform,
            'accepted_language' => $lang,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PrivacyResource extends JsonResource
{
    public function toArray($request)
    {
        // 1) Resolve language from Accept-Language (e.g., "ar-EG" -> "ar")
        $raw = (string) $request->header('Accept-Language', 'en');
        $norm = str_replace('_', '-', strtolower($raw));
        $lang = substr($norm, 0, 2);
        if (! in_array($lang, ['ar', 'en'], true)) {
            $lang = 'en';
        }

        // 2) Prefer Astrotomic Translatable if available, else column-per-lang, else generic
        $title = null;
        $content = null;

        // If model uses Astrotomic\Translatable
        if (method_exists($this, 'translate')) {
            $tr = $this->translate($lang) ?? $this->translate('en');
            if ($tr) {
                $title   = $tr->title   ?? $title;
                $content = $tr->content ?? $content;
            }
        }

        // Column-per-language fallback (title_en/title_ar, content_en/content_ar) or plain columns
        if ($title === null || $content === null) {
            $titleCol   = $lang === 'ar' ? 'title_ar'   : 'title_en';
            $contentCol = $lang === 'ar' ? 'content_ar' : 'content_en';

            $title   = $title   ?? ($this->{$titleCol}   ?? $this->title_en   ?? $this->title   ?? null);
            $content = $content ?? ($this->{$contentCol} ?? $this->content_en ?? $this->content ?? null);
        }

        return [
            // include accepted language inside the resource payload (mobile-friendly)
            'accepted_language' => $lang,

            // localized fields
            'title'      => $title,
            'content'    => $content,

            // useful metadata
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TermsResource extends JsonResource
{
    public function toArray($request)
    {
        // Resolve language from Accept-Language (e.g., "ar-EG" -> "ar")
        $raw = (string) $request->header('Accept-Language', 'en');
        $norm = str_replace('_', '-', strtolower($raw));
        $lang = substr($norm, 0, 2);
        if (! in_array($lang, ['ar', 'en'], true)) {
            $lang = 'en';
        }

        // Prefer Astrotomic Translatable (terms + terms->translations)
        $title = null;
        $content = null;

        if (method_exists($this, 'translate')) {
            $tr = $this->translate($lang) ?? $this->translate('en');
            if ($tr) {
                $title   = $tr->title   ?? $title;
                $content = $tr->content ?? $content;
            }
        }

        // Column-per-language fallback (title_en/title_ar, content_en/content_ar), then generic
        if ($title === null || $content === null) {
            $titleCol   = $lang === 'ar' ? 'title_ar'   : 'title_en';
            $contentCol = $lang === 'ar' ? 'content_ar' : 'content_en';

            $title   = $title   ?? ($this->{$titleCol}   ?? $this->title_en   ?? $this->title   ?? null);
            $content = $content ?? ($this->{$contentCol} ?? $this->content_en ?? $this->content ?? null);
        }

        return [
            'accepted_language' => $lang,
            'title'             => $title,
            'content'           => $content,
            'updated_at'        => optional($this->updated_at)->toISOString(),
        ];
    }
}

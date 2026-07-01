<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductCategoryResource extends JsonResource
{
    public function toArray($request)
    {
        // Accept-Language -> ar|en (default en)
        $raw  = (string) $request->header('Accept-Language', 'en');
        $lang = substr(strtolower(str_replace('_','-',$raw)), 0, 2);
        if (! in_array($lang, ['ar','en'], true)) $lang = 'en';

        // Name/description via Astrotomic (fallback EN), then column-per-lang
        $name = $desc = null;

        if (method_exists($this, 'translate')) {
            $tr   = $this->translate($lang) ?? $this->translate('en');
            $name = $tr->name ?? null;
            $desc = $tr->description ?? null;
        }

        if ($name === null || $desc === null) {
            $nameCol = $lang === 'ar' ? 'name_ar' : 'name_en';
            $descCol = $lang === 'ar' ? 'description_ar' : 'description_en';
            $name = $name ?? ($this->{$nameCol} ?? $this->name_en ?? $this->name ?? null);
            $desc = $desc ?? ($this->{$descCol} ?? $this->description_en ?? $this->description ?? null);
        }

        return [
            'id'                => $this->id,
            'slug'              => $this->slug ?? null,
            'accepted_language' => $lang,
            'name'              => $name,
            'description'       => $desc,

        //    'image'             => $this->image,
            'image'         => $this->image ? Storage::disk('public')->url($this->image) : null,

            // when() avoids extra COUNT if not present
            'products_count'    => $this->when(isset($this->products_count), (int) $this->products_count),

            // include children if relation was eager-loaded
            'children'          => self::collection($this->whenLoaded('children')),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
{
    public function toArray($request)
    {
        $raw = (string) $request->header('Accept-Language', 'ar');
        $lang = substr(strtolower(str_replace('_', '-', $raw)), 0, 2);
        if (! in_array($lang, ['ar', 'en'], true)) {
            $lang = 'ar';
        }

        $name = $this->resource->normalizedDisplayName($lang);
        $short = $this->resource->shortDescription($lang);
        $imageUrl = $this->resource->resolvedImageUrl();

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'slug' => $this->slug ?? null,
            'accepted_language' => $lang,
            'name' => $name,
            'description' => $short,
            'short_description' => $short,
            // Path only when file exists; never a 404 URL.
            'image' => $imageUrl ? $this->image : null,
            'image_url' => $imageUrl,
            'icon_url' => $imageUrl,
            'badge' => $this->resource->systemBadge(),
            'products_count' => $this->when(isset($this->products_count), (int) $this->products_count),
            'tags' => $this->whenLoaded('tags', fn ($tags) => $tags->pluck('name')->values()->all()),
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}

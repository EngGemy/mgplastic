<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductResource extends JsonResource
{
    public function toArray($request)
    {
        // ---- Accept-Language -> ar|en (default en)
        $raw  = (string) $request->header('Accept-Language', 'en');
        $lang = substr(strtolower(str_replace('_','-',$raw)), 0, 2);
        if (! in_array($lang, ['ar','en'], true)) $lang = 'en';

        // ---- Localized fields (Astrotomic first, fallback EN, then column-per-lang)
        $name = $description = $usage = null;
        if (method_exists($this, 'translate')) {
            $t = $this->translate($lang) ?? $this->translate('en');
            $name        = $t->name ?? null;
            $description = $t->description ?? null;
            $usage       = $t->usage ?? null;
        }
        if ($name === null || $description === null || $usage === null) {
            $nameCol  = $lang === 'ar' ? 'name_ar'        : 'name_en';
            $descCol  = $lang === 'ar' ? 'description_ar' : 'description_en';
            $usageCol = $lang === 'ar' ? 'usage_ar'       : 'usage_en';
            $name        = $name        ?? ($this->{$nameCol} ?? $this->name_en ?? $this->name ?? null);
            $description = $description ?? ($this->{$descCol} ?? $this->description_en ?? $this->description ?? null);
            $usage       = $usage       ?? ($this->{$usageCol} ?? $this->usage_en ?? $this->usage ?? null);
        }

        // ---- Category (name + slug)
        $categoryName = null;
        $categorySlug = null;
        if ($this->relationLoaded('category') && $this->category) {
            $categorySlug = strtolower($this->category->slug ?? '');
            if (method_exists($this->category, 'translate')) {
                $ct = $this->category->translate($lang) ?? $this->category->translate('en');
                $categoryName = $ct->name ?? null;
            }
            if ($categoryName === null) {
                $catNameCol   = $lang === 'ar' ? 'name_ar' : 'name_en';
                $categoryName = $this->category->{$catNameCol} ?? $this->category->name_en ?? $this->category->name ?? null;
            }
        }

        // ---- Standard / Color names
        $standardName = ($this->relationLoaded('standard') && $this->standard)
            ? (method_exists($this->standard, 'translate')
                ? ($this->standard->translate($lang)->name ?? $this->standard->name ?? null)
                : ($this->standard->name ?? null))
            : null;

        $colorName = ($this->relationLoaded('color') && $this->color)
            ? (method_exists($this->color, 'translate')
                ? ($this->color->translate($lang)->name ?? $this->color->name ?? null)
                : ($this->color->name ?? null))
            : null;

        // ---- Main image URL (supports storage path or absolute URL)
        $mainImage    = $this->main_image;
        $mainImageUrl = $mainImage
            ? ((Str::startsWith($mainImage, ['products/','sizes/']))
                ? Storage::disk('public')->url($mainImage)
                : $mainImage)
            : null;

        // ---- Gallery (if loaded)
        $images = [];
        if ($this->relationLoaded('images')) {
            $images = $this->images
                ->sortBy('sort')
                ->values()
                ->map(function ($img) {
                    $path = $img->image ?? $img->path;
                    $url  = Str::startsWith($path, ['http','https'])
                        ? $path
                        : Storage::disk('public')->url($path);
                    return [
                        'id'   => (int) $img->id,
                        'path' => $path,
                        'url'  => $url,
                        'sort' => (int) ($img->sort ?? 0),
                    ];
                })->all();
        }

        // ---- Model "variants" relation (keep if you use it)
        $variants = [];
        if ($this->relationLoaded('variants')) {
            $variants = $this->variants->map(function ($v) {
                return [
                    'id'                 => (int) $v->id,
                    'catalog_code'       => $v->catalog_code,
                    'outer_diameter_mm'  => $v->outer_diameter_mm,
                    'wall_thickness_mm'  => $v->wall_thickness_mm,
                    'insertion_depth_mm' => $v->insertion_depth_mm,
                    'weight_kg_per_m'    => $v->weight_kg_per_m,
                    'pressure_class'     => $v->pressure_class,
                    'width_w_mm'         => $v->width_w_mm,
                    'height_l_mm'        => $v->height_l_mm,
                    'depth_h_mm'         => $v->depth_h_mm,
                    'depth_h1_mm'        => $v->depth_h1_mm,
                    'depth_h2_mm'        => $v->depth_h2_mm,
                    'depth_h3_mm'        => $v->depth_h3_mm,
                    'd1_mm'              => $v->d1_mm,
                    'd2_mm'              => $v->d2_mm,
                    'd3_mm'              => $v->d3_mm,
                    'd4_mm'              => $v->d4_mm,
                    'extra'              => $v->extra,
                ];
            })->all();
        }

        // ---- Detect accessories
        $isAccessory = false;
        if ($categorySlug) {
            $isAccessory = in_array($categorySlug, ['accessories','accessory','acc'], true);
        }
        if (! $isAccessory && $categoryName) {
            $cn = mb_strtolower($categoryName);
            $isAccessory = str_contains($cn, 'accessor') || str_contains($cn, 'ملحق');
        }

        // ---- Sizes (only for accessories) — inline to keep this resource self-contained
        $sizes = [];
        if ($isAccessory && $this->relationLoaded('sizes')) {
            $sizes = $this->sizes->sortBy('sort')->values()->map(function ($s) use ($lang) {
                // localize label if you have columns label_en/label_ar; otherwise fallback to name/code
                $label = $lang === 'ar'
                    ? ($s->label_ar ?? $s->name_ar ?? $s->name ?? $s->code)
                    : ($s->label_en ?? $s->name_en ?? $s->name ?? $s->code);

                $systemName = null;
                if (method_exists($s, 'system') && $s->relationLoaded('system') && $s->system) {
                    $systemName = $lang === 'ar'
                        ? ($s->system->name_ar ?? $s->system->name ?? $s->system->code)
                        : ($s->system->name_en ?? $s->system->name ?? $s->system->code);
                }

                // image if you store it
                $img = $s->image ?? null;
                $imgUrl = $img
                    ? (Str::startsWith($img, ['sizes/','products/'])
                        ? Storage::disk('public')->url($img)
                        : $img)
                    : null;

                return [
                    'id'        => (int) $s->id,
                    'code'      => (string) ($s->code ?? ''),
                    'label'     => (string) $label,
                    'image_url' => $imgUrl,
                    'system'    => $s->system ? [
                        'id'   => (int) ($s->system->id ?? 0),
                        'code' => strtoupper((string) ($s->system->code ?? '')),
                        'name' => $systemName,
                    ] : null,
                ];
            })->all();
        }

        // ---- Titles dictionary (both languages) for varients
        $titleMap = [
            'volume_ml'     => ['en' => 'Volume (BAR)',     'ar' => 'الضفط (BAR)'],
            'thickness_mm'  => ['en' => 'Thickness (mm)',  'ar' => 'السُمك (ملليمتر)'],

            'length_m'      => ['en' => 'Length (m)',      'ar' => 'الطول (متر)'],
       //     'classification'=> ['en' => 'Classification',  'ar' => 'التصنيف'],
        ];
        $localTitle = function (string $key) use ($lang, $titleMap): array {
            $en = $titleMap[$key]['en'] ?? Str::headline(str_replace('_',' ', $key));
            $ar = $titleMap[$key]['ar'] ?? $en;
            return [
                'title'     => $lang === 'ar' ? $ar : $en, // localized for current request
                'title_en'  => $en,
                'title_ar'  => $ar,
            ];
        };

        // ---- Build varients array (for standard products only)
        $varients = [];
        if (! $isAccessory) {
            $specPairs = [        'thickness_mm'  => $this->thickness_mm,
                'volume_ml'     => $this->volume_ml,
                'length_m'      => $this->length_m,

              //  'classification'=> $this->classification,
            ];

            foreach ($specPairs as $key => $val) {
                // skip completely null values to keep UI clean
                if ($val === null || $val === '') continue;

                $t = $localTitle($key);
                $varients[] = [
                    'key'       => $key,
                    'title'     => $t['title'],
                    'title_en'  => $t['title_en'],
                    'title_ar'  => $t['title_ar'],
                    'value'     => $val,
                ];
            }
        }

        // ---- Base payload
        $payload = [
            'id'                    => (int) $this->id,
            'accepted_language'     => $lang,
            'name'                  => (string) $name,
            'description'           => (string) ($description ?? ''),
            'usage'                 => (string) ($usage ?? ''),
            'product_category_id'   => $this->product_category_id,
            'category_name'         => $categoryName,
            'product_standard_id'   => $this->product_standard_id,
            'standard_name'         => $standardName,
            'product_color_id'      => $this->product_color_id,
            'color_name'            => $colorName,
            'main_image'            => $mainImageUrl,
            'main_image_url'        => $mainImageUrl,
            'images'                => $images,
            'meta'                  => $this->meta ?? new \stdClass(),
            'created_at'            => optional($this->created_at)->toISOString(),
            'updated_at'            => optional($this->updated_at)->toISOString(),
            'type'                  => $isAccessory ? 'accessory' : 'standard',
            'varients'              => $isAccessory ? [] : $varients, // ALWAYS present
            'notes'                 => $this->notes,
            'variants'              => $variants, // model-level variants, if any
            'sizes'                 => $isAccessory ? $sizes : [],   // sizes only for accessories
        ];

        return $payload;
    }
}

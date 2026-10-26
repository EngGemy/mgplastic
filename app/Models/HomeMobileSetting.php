<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;

class HomeMobileSetting extends Model
{
    protected $fillable = [
        'banner_title',
        'banner_subtitle',
        'banner_cta_text',
        'banner_cta_link',
        'banner_image',
        'banner_is_active',
        'main_sections_title',
        'new_products_title',
        'stores_title',
    ];

    protected function casts(): array
    {
        return [
            'banner_is_active' => 'boolean',
        ];
    }

    public static function instance(): self
    {
        return static::query()->firstOrCreate([], static::defaults());
    }

    public static function defaults(): array
    {
        return [
            'banner_title' => 'منتجات MG',
            'banner_subtitle' => 'أداء أعلى ... واعتمادية تدوم',
            'banner_cta_text' => 'اكتشف المزيد',
            'banner_cta_link' => 'mgplastic://catalog',
            'banner_image' => null,
            'banner_is_active' => true,
            'main_sections_title' => 'الأقسام الرئيسية',
            'new_products_title' => 'جديد منتجات MG',
            'stores_title' => 'المتاجر',
        ];
    }

    public function bannerImageUrl(): ?string
    {
        return PublicStorageUrl::try($this->banner_image);
    }

    /** @return array<string, mixed> */
    public function toApiLayout(): array
    {
        return [
            'banner' => [
                'title' => (string) $this->banner_title,
                'subtitle' => $this->banner_subtitle,
                'cta_text' => (string) $this->banner_cta_text,
                'cta_link' => $this->banner_cta_link,
                'image_url' => $this->bannerImageUrl(),
                'is_active' => (bool) $this->banner_is_active,
            ],
            'titles' => [
                'main_sections' => (string) $this->main_sections_title,
                'new_products' => (string) $this->new_products_title,
                'stores' => (string) $this->stores_title,
            ],
        ];
    }
}

<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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
        'layout_version',
    ];

    protected function casts(): array
    {
        return [
            'banner_is_active' => 'boolean',
            'layout_version' => 'integer',
        ];
    }

    public static function instance(): self
    {
        // Request-local + short shared cache — home layout reads this every hit.
        return once(function () {
            $cached = Cache::get('home_mobile:settings');
            if ($cached instanceof self) {
                return $cached;
            }

            $row = static::query()->firstOrCreate([], static::defaults());
            Cache::put('home_mobile:settings', $row, 60);

            return $row;
        });
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
            'layout_version' => 1,
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

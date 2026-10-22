<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;

class PromoBanner extends Model
{
    public const SECTION_BETWEEN_PLUMBERS_STORES = 'between_plumbers_stores';

    protected $fillable = [
        'title',
        'subtitle',
        'image_path',
        'section',
        'link_type',
        'link_id',
        'link_url',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'link_id' => 'integer',
        ];
    }

    public function imageUrl(): ?string
    {
        return PublicStorageUrl::try($this->image_path);
    }

    protected function setImagePathAttribute(mixed $value): void
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        $this->attributes['image_path'] = is_string($value) ? $value : null;
    }

    /** @return array<string, mixed>|null */
    public function resolveLinkPayload(): ?array
    {
        return match ($this->link_type) {
            'url' => [
                'type' => 'url',
                'url' => $this->link_url,
            ],
            'product_collection' => [
                'type' => 'product_collection',
                'id' => $this->link_id,
                'path' => '/api/v1/mobile/product-collections/'.($this->link_id ?: ''),
            ],
            'product_promotion' => [
                'type' => 'product_promotion',
                'id' => $this->link_id,
                'path' => '/api/v1/mobile/product-promotions/'.($this->link_id ?: ''),
            ],
            'product' => [
                'type' => 'product',
                'id' => $this->link_id,
            ],
            default => [
                'type' => 'none',
            ],
        };
    }
}

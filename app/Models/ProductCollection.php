<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductCollection extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'subtitle',
        'description',
        'kind',
        'cover_image',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $collection) {
            if (! filled($collection->slug) && filled($collection->title)) {
                $base = Str::slug($collection->title) ?: 'collection';
                $slug = $base;
                $i = 1;
                while (static::query()
                    ->where('slug', $slug)
                    ->when($collection->exists, fn ($q) => $q->whereKeyNot($collection->id))
                    ->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $collection->slug = $slug;
            }
        });
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_collection_product')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function coverImageUrl(): ?string
    {
        return $this->cover_image
            ? Storage::disk('public')->url($this->cover_image)
            : null;
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'featured' => 'منتجات مميزة',
            'new' => 'منتجات حديثة',
            'important' => 'أهم المنتجات',
            default => 'مجموعة',
        };
    }
}

<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
            $collection->slug = static::uniqueSlugFor(
                (string) ($collection->slug ?: ''),
                (string) ($collection->title ?: ''),
                $collection->exists ? (int) $collection->id : null,
            );
        });
    }

    /**
     * Build a unique slug ≤ 80 chars (DB column limit).
     * Always re-checks uniqueness so create never 500s on duplicate titles.
     */
    public static function uniqueSlugFor(string $slug, string $title = '', ?int $ignoreId = null): string
    {
        $base = filled($slug) ? Str::slug($slug) : '';
        if (! filled($base) && filled($title)) {
            $base = Str::slug($title);
        }
        if (! filled($base)) {
            $base = 'collection-'.Str::lower(Str::random(6));
        }

        // Leave room for "-999" suffix inside varchar(80).
        $base = Str::limit($base, 76, '');

        $candidate = $base;
        $i = 1;
        while (static::query()
            ->where('slug', $candidate)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $candidate = Str::limit($base, 76, '').'-'.$i++;
        }

        return $candidate;
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_collection_product')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function coverImageUrl(): ?string
    {
        return PublicStorageUrl::try($this->cover_image);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'featured' => 'مميزة',
            'new' => 'حديثة',
            'important' => 'مهمة',
            default => 'مخصّصة',
        };
    }
}

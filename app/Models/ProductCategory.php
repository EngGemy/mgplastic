<?php

namespace App\Models;

use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductCategory extends Model
{
    use HasFactory, Translatable;

    public $translatedAttributes = ['name', 'description'];

    protected $fillable = ['image', 'parent_id', 'slug'];

    // ✅ استخدم اسم العمود الحقيقي في جدول products
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_category_id'); // <-- غيّرها لو اسمك مختلف
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(ProductTag::class, 'product_category_id');
    }

    /**
     * @param  list<mixed>  $names
     */
    public function syncOwnedTagNames(array $names): void
    {
        $names = ProductTag::normalizeNames($names);
        $existing = $this->tags()->get()->keyBy(fn (ProductTag $tag) => mb_strtolower($tag->name));
        $keepIds = [];

        foreach ($names as $name) {
            $key = mb_strtolower($name);
            $tag = $existing->get($key);
            if ($tag) {
                if ($tag->name !== $name) {
                    $tag->name = $name;
                    $tag->save();
                }
            } else {
                $tag = $this->tags()->create(['name' => $name]);
            }
            $keepIds[] = $tag->id;
        }

        $this->tags()->whereNotIn('id', $keepIds === [] ? [0] : $keepIds)->delete();
    }

    // اختياري: اسم مترجم سريع
    public function getNameAttribute(): ?string
    {
        return optional($this->translate('ar'))->name
            ?? optional($this->translate(app()->getLocale()))->name
            ?? $this->slug;
    }

    /** إرجاع true لو التصنيف نفسه أو أي أب ليه يقع ضمن “Accessories / ملحقات” */
    public function isAccessoriesFamily(): bool
    {
        $node = $this;

        while ($node) {
            $slug = Str::of((string) $node->slug)->lower()->toString();

            // أسماء مترجمة (لو متوفرة)
            $nameEn = mb_strtolower((string) optional($node->translate('en'))->name);
            $nameAr = mb_strtolower((string) optional($node->translate('ar'))->name);

            // التطبيع
            $hit = false;
            // إنجليزي: accessory / accessories
            if (Str::contains($slug, 'Accessor') || Str::contains($nameEn, 'Accessor')) {
                $hit = true;
            }
            // عربي: ملحق / ملحقات
            if (Str::contains($slug, 'ملحقات') || Str::contains($nameAr, 'ملحق')) {
                $hit = true;
            }

            if ($hit) {
                return true;
            }

            $node = $node->parent; // اصعد للأب
        }

        return false;
    }
}

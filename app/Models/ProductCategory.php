<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
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

    /** Absolute image URL, or null when the file is missing (no broken links). */
    public function resolvedImageUrl(): ?string
    {
        return PublicStorageUrl::try($this->image);
    }

    /**
     * Small badge for system cards (EN / ASTM / …), derived from name/slug.
     */
    public function systemBadge(): ?string
    {
        $blob = mb_strtolower(trim(implode(' ', array_filter([
            (string) $this->slug,
            $this->normalizedDisplayName('ar'),
            $this->normalizedDisplayName('en'),
        ]))));

        return match (true) {
            Str::contains($blob, ['اوروب', 'أوروب', 'europ', 'european'])
                || (bool) preg_match('/(^|[\s_\-])en([\s_\-]|$)/u', $blob) => 'EN',
            Str::contains($blob, ['امريك', 'أمريك', 'american', 'astm', 'schedule']) => 'ASTM',
            Str::contains($blob, ['ذهب', 'gold']) => null,
            default => null,
        };
    }

    /**
     * Short card blurb for mobile home / systems (plain text, ≤120 chars).
     */
    public function shortDescription(?string $locale = null): ?string
    {
        $locale = $locale ?: app()->getLocale();
        $raw = trim((string) (
            optional($this->translate('ar'))->description
            ?? optional($this->translate($locale))->description
            ?? optional($this->translate('en'))->description
            ?? ''
        ));

        if ($raw === '') {
            $raw = $this->defaultSystemBlurb();
        }

        if ($raw === '') {
            return null;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '');

        return $plain !== '' ? Str::limit($plain, 120, '…') : null;
    }

    public function normalizedDisplayName(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();
        $name = trim((string) (
            optional($this->translate($locale))->name
            ?? optional($this->translate('ar'))->name
            ?? optional($this->translate('en'))->name
            ?? $this->slug
            ?? ('تصنيف #'.$this->id)
        ));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = str_replace(['الاوروربي', 'الأوروربي'], 'الاوروبي', $name);

        return trim($name);
    }

    protected function defaultSystemBlurb(): string
    {
        $blob = mb_strtolower(trim(implode(' ', array_filter([
            (string) $this->slug,
            $this->normalizedDisplayName('ar'),
            $this->normalizedDisplayName('en'),
        ]))));

        return match (true) {
            Str::contains($blob, ['اوروب', 'أوروب', 'europ']) => 'مقاسات ومواصفات · EN Standard',
            Str::contains($blob, ['امريك', 'أمريك', 'american', 'astm', 'schedule']) => 'Schedule / ASTM',
            Str::contains($blob, ['ذهب', 'gold']) => 'مواسير النظام الذهبي',
            Str::contains($blob, ['برتق', 'orange']) => 'نظام البرتقالي للصرف والتمديدات',
            Str::contains($blob, ['رماد', 'grey', 'gray']) => 'النظام الرمادي',
            Str::contains($blob, ['ابار', 'آبار', 'well']) => 'مواسير الآبار والطلمبات',
            default => '',
        };
    }

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

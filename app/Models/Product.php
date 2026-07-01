<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class Product extends Model
{
    use HasFactory, Translatable;

    public $translatedAttributes = ['name', 'description', 'usage'];

    protected $fillable = [
        'product_category_id',
        'product_standard_id',
        'product_color_id',
        'length_m',
        'thickness_mm',
        'volume_ml',
        'classification',
        'notes',
        'main_image',
        'meta',

        // ✅ الحقول الجديدة
        'catalog_image_path',
        'catalog_image_mime',
        'catalog_image_size',
        'catalog_pdf_path',
        'catalog_pdf_mime',
        'catalog_pdf_size',
        'points_per_unit',
        'point_value_type',
        'point_value_percent',
        'point_value_fixed',
        'reference_unit_price_cents',
    ];

    protected $casts = [
        'length_m'     => 'float',
        'thickness_mm' => 'float',
        'volume_ml'    => 'float',
        'meta'         => 'array',
        'points_per_unit' => 'decimal:4',
        'point_value_percent' => 'decimal:4',
        'point_value_fixed' => 'decimal:4',
        'reference_unit_price_cents' => 'integer',
    ];

    // --- Relations ---
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(ProductStandard::class, 'product_standard_id');
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(ProductColor::class, 'product_color_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function productSizes(): HasMany
    {
        return $this->hasMany(ProductSize::class);
    }

    // ✅ Many-to-many sizes relation
    public function sizes(): BelongsToMany
    {
        return $this->belongsToMany(Size::class, 'product_sizes')->withTimestamps();
    }

    public function sizesUs(): BelongsToMany
    {
        return $this->sizes()->whereHas('system', fn ($q) => $q->where('code', 'us'));
    }

    public function sizesEu(): BelongsToMany
    {
        return $this->sizes()->whereHas('system', fn ($q) => $q->where('code', 'eu'));
    }

    // --- Accessors & Helpers ---

    /** الحجم باللتر من الملي */
    public function getVolumeLitersAttribute(): ?float
    {
        return $this->volume_ml !== null ? round($this->volume_ml / 1000, 3) : null;
    }

    /** هل التصنيف Accessories/ملحقات؟ */
    public function isAccessories(): bool
    {
        $cat = $this->category?->loadMissing('parent.translations','translations');
        return $cat ? $cat->isAccessoriesFamily() : false;
    }

    /** إجمالي القيمة المالية لنقاط وحدة واحدة (د.ل) */
    public function pointMonetaryValuePerUnit(?int $unitPriceCents = null): float
    {
        $priceCents = $unitPriceCents ?? $this->reference_unit_price_cents ?? 0;
        $points = (float) $this->points_per_unit;

        if ($this->point_value_type === 'percent' && $this->point_value_percent && $priceCents > 0) {
            return round(($priceCents / 100) * ((float) $this->point_value_percent / 100), 4);
        }

        if ($this->point_value_type === 'fixed' && $this->point_value_fixed && $points > 0) {
            return round($points * (float) $this->point_value_fixed, 4);
        }

        return 0.0;
    }

    /** قيمة النقطة الواحدة (د.ل) */
    public function pointMonetaryValuePerPoint(?int $unitPriceCents = null): float
    {
        $points = (float) $this->points_per_unit;
        if ($points <= 0) {
            return 0.0;
        }

        return round($this->pointMonetaryValuePerUnit($unitPriceCents) / $points, 4);
    }

    public function pointValueTypeLabel(): string
    {
        return match ($this->point_value_type) {
            'percent' => 'نسبة',
            'fixed' => 'ثابت',
            default => '—',
        };
    }

    public function pointConversionSummary(): string
    {
        if ($this->point_value_type === 'percent') {
            $price = $this->reference_unit_price_cents
                ? number_format($this->reference_unit_price_cents / 100, 2).' د.ل'
                : '—';

            return number_format((float) $this->point_value_percent, 2).'% من '.$price;
        }

        if ($this->point_value_type === 'fixed') {
            return number_format((float) $this->point_value_fixed, 4).' د.ل / نقطة';
        }

        return '—';
    }


    /**
     * ✅ إجبار إرجاع قيمة الـ path بشكل صحيح حتى لو اتخزنت array/URL
     * هذي Accessors على نفس الأعمدة
     */
    public function getCatalogImagePathAttribute($value): ?string
    {
        return $this->normalizePathStringOrArray($value);
    }

    public function getCatalogPdfPathAttribute($value): ?string
    {
        return $this->normalizePathStringOrArray($value);
    }

    /** ✅ URLs جاهزة */
    public function getCatalogImageUrlAttribute(): ?string
    {
        return $this->toPublicUrl($this->catalog_image_path);
    }

    public function getCatalogPdfUrlAttribute(): ?string
    {
        return $this->toPublicUrl($this->catalog_pdf_path);
    }

    /** ✅ فلاغات للواجهة */
    public function getHasCatalogImageAttribute(): bool
    {
        return (bool) $this->catalog_image_path;
    }

    public function getHasCatalogPdfAttribute(): bool
    {
        return (bool) $this->catalog_pdf_path;
    }

    /** تضمين المحسوبات في JSON */
    protected $appends = [
        'catalog_image_url',
        'catalog_pdf_url',
        'has_catalog_image',
        'has_catalog_pdf',
    ];

    // --- Helpers ---
    private function normalizePathStringOrArray(mixed $value): ?string
    {
        if (is_array($value)) {
            // أحيانًا FileUpload بيرجع ['path' => '...'] أو ['url'=>'...']
            $value = $value['path'] ?? $value['url'] ?? null;
        }
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function toPublicUrl(?string $path): ?string
    {
        if (!$path) return null;
        // لو هو URL كامل، رجّعه زي ما هو
        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            return $path;
        }
        // لو بدأ بـ / شيله علشان Storage ما يكرر /
        $path = ltrim($path, '/');
        return Storage::disk('public')->url($path);
    }

    /** الحجم باللتر من الملي */


}

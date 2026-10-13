<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class WebsiteSetting extends Model
{
    public const DEFAULT_LOGO = 'images/logo-light.png';

    protected $fillable = [
        'site_name', 'site_domain', 'seo_title', 'seo_description',
        'about_eyebrow', 'about_title', 'about_subtitle', 'about_paragraphs',
        'about_image', 'about_badge_year', 'about_badge_text', 'about_values',
        'contact_phone', 'price_list_phones', 'contact_whatsapp', 'contact_email',
        'contact_address', 'contact_address_detail',
        'contact_work_days', 'contact_work_hours',
        'map_latitude', 'map_longitude',
        'points_eyebrow', 'points_title', 'points_subtitle',
        'points_chain', 'points_features',
        'footer_tagline', 'catalog_pdf',
    ];

    protected $casts = [
        'about_paragraphs' => 'array',
        'price_list_phones' => 'array',
        'about_values' => 'array',
        'points_chain' => 'array',
        'points_features' => 'array',
        'map_latitude' => 'float',
        'map_longitude' => 'float',
    ];

    public static function instance(): self
    {
        return static::query()->firstOrCreate([], static::defaults());
    }

    /**
     * @return list<string>
     */
    public static function defaultPriceListPhones(): array
    {
        return ['0916126065', '0919842523', '0912159316', '0922159316'];
    }

    /**
     * Phones printed on every price list. Null means the factory numbers.
     *
     * @return list<string>
     */
    public function priceListPhones(): array
    {
        if (! is_array($this->price_list_phones)) {
            return self::defaultPriceListPhones();
        }

        return collect($this->price_list_phones)
            ->map(fn ($phone) => trim((string) $phone))
            ->filter()
            ->values()
            ->all();
    }

    public static function defaults(): array
    {
        return [
            'site_name' => 'MG Plastic',
            'site_domain' => 'mg-plastic.ly',
            'seo_title' => 'MG Plastic — مصنع أدوات السباكة في ليبيا',
            'seo_description' => 'MG Plastic مصنع ليبي متخصص في تصنيع أنابيب PVC وأدوات السباكة — نظام توزيع النقاط الأول في ليبيا',
            'about_eyebrow' => 'About MG Plastic',
            'about_title' => 'قصتنا في صناعة المستقبل',
            'about_subtitle' => '// built_in_libya.for_libya',
            'about_paragraphs' => [
                'مصنع MG Plastic تأسس في طرابلس عام ٢٠١٠ برؤية واضحة: تصنيع أدوات سباكة تنافس المستورد بجودة موثوقة وسعر مناسب للسوق الليبي.',
                'على مدار أكثر من ١٥ عاماً، بنينا شبكة توزيع تمتد لكل ليبيا — من طرابلس لبنغازي لمصراتة — مع أكثر من ٥٠ موزع معتمد وما يزيد على ٥٠٠ سباك مسجل في منصتنا الرقمية.',
                'اليوم نقود تحولاً رقمياً في القطاع بإطلاق نظام توزيع النقاط الأول في ليبيا — ربط كامل من المصنع حتى السباك بشفافية تامة.',
            ],
            'about_badge_year' => '٢٠١٠',
            'about_badge_text' => 'طرابلس — ليبيا',
            'about_values' => [
                ['icon' => 'ti-certificate', 'title' => 'جودة معتمدة', 'desc' => 'مطابق للمواصفات الليبية والأوروبية'],
                ['icon' => 'ti-shield-check', 'title' => 'ضمان شامل', 'desc' => 'ضمان على جميع منتجاتنا'],
                ['icon' => 'ti-truck', 'title' => 'توزيع وطني', 'desc' => 'تغطية كاملة لمدن ليبيا'],
                ['icon' => 'ti-star', 'title' => 'نظام النقاط', 'desc' => 'مكافأة السباكين على ولائهم'],
            ],
            'contact_phone' => '+218913456789',
            'price_list_phones' => self::defaultPriceListPhones(),
            'contact_whatsapp' => '+218913456789',
            'contact_email' => 'info@mg-plastic.ly',
            'contact_address' => 'طرابلس، ليبيا',
            'contact_address_detail' => 'شارع عمر المختار — المنطقة الصناعية',
            'contact_work_days' => 'الأحد – الخميس',
            'contact_work_hours' => '٨:٠٠ صباحاً – ٥:٠٠ مساءً',
            'map_latitude' => 32.8872,
            'map_longitude' => 13.1913,
            'points_eyebrow' => 'Points Distribution System',
            'points_title' => 'نظام توزيع النقاط',
            'points_subtitle' => '// factory → distributor → trader → plumber',
            'points_chain' => [
                ['title' => 'المصنع — MG Plastic', 'subtitle' => 'يحدد النقاط ويعتمد الفواتير', 'color' => '#60a5fa'],
                ['title' => 'موزع الجملة', 'subtitle' => 'يوزع على تجار القطاعي', 'color' => '#38bdf8'],
                ['title' => 'تاجر القطاعي', 'subtitle' => 'يبيع للسباكين ويحول النقاط', 'color' => '#34d399'],
                ['title' => 'السباك ← النقاط هنا', 'subtitle' => 'يكسب النقاط ويسحبها فلوساً', 'color' => '#fbbf24'],
            ],
            'points_features' => [
                ['title' => '١٠٠٪ شفافية', 'desc' => 'كل نقطة مرصودة من الفاتورة للمحفظة'],
                ['title' => 'تحويل فوري', 'desc' => 'نقاطك تصلك لحظة تأكيد البيع'],
                ['title' => 'سحب مرن', 'desc' => 'اسحب نقاطك فلوساً متى شئت'],
                ['title' => 'لا تسريب', 'desc' => 'النقاط للسباك فقط — مضمونة'],
            ],
            'footer_tagline' => 'مصنع أدوات السباكة الأول في ليبيا — تصنيع وتوزيع بمعايير عالية منذ ٢٠١٠.',
        ];
    }

    /** Same logo as the main Filament admin panel (`brandLogo`). */
    public function getSiteLogoUrlAttribute(): string
    {
        return asset(self::DEFAULT_LOGO);
    }

    public function getAboutImageUrlAttribute(): ?string
    {
        return $this->about_image ? Storage::disk('public')->url($this->about_image) : null;
    }

    public function getCatalogPdfUrlAttribute(): ?string
    {
        return $this->catalog_pdf ? Storage::disk('public')->url($this->catalog_pdf) : null;
    }

    /**
     * Flatten nested repeater / JSON shapes into plain paragraph strings.
     *
     * @return list<string>
     */
    public static function normalizeParagraphs(mixed $paragraphs): array
    {
        return collect(is_array($paragraphs) ? $paragraphs : [])
            ->map(function ($item) {
                $depth = 0;
                while (is_array($item) && $depth < 5) {
                    $item = $item['paragraph'] ?? reset($item);
                    $depth++;
                }

                if (! is_scalar($item) || $item === null) {
                    return '';
                }

                return trim((string) $item);
            })
            ->filter(fn (string $text) => $text !== '')
            ->values()
            ->all();
    }

    /** Normalize Filament FileUpload state (array|string|null) to a stored path. */
    public static function normalizeUploadPath(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)->filter()->first();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}

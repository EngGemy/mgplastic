<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class WebsiteSetting extends Model
{
    protected $fillable = [
        'site_name', 'site_domain', 'seo_title', 'seo_description',
        'about_eyebrow', 'about_title', 'about_subtitle', 'about_paragraphs',
        'about_image', 'about_badge_year', 'about_badge_text', 'about_values',
        'contact_phone', 'contact_whatsapp', 'contact_email',
        'contact_address', 'contact_address_detail',
        'contact_work_days', 'contact_work_hours',
        'map_latitude', 'map_longitude',
        'points_eyebrow', 'points_title', 'points_subtitle',
        'points_chain', 'points_features',
        'footer_tagline', 'catalog_pdf',
    ];

    protected $casts = [
        'about_paragraphs' => 'array',
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

    public function getAboutImageUrlAttribute(): ?string
    {
        return $this->about_image ? Storage::disk('public')->url($this->about_image) : null;
    }

    public function getCatalogPdfUrlAttribute(): ?string
    {
        return $this->catalog_pdf ? Storage::disk('public')->url($this->catalog_pdf) : null;
    }
}

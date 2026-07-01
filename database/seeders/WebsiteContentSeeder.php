<?php

namespace Database\Seeders;

use App\Models\Slider;
use App\Models\WebsiteService;
use App\Models\WebsiteSetting;
use App\Models\WebsiteStat;
use Illuminate\Database\Seeder;

class WebsiteContentSeeder extends Seeder
{
    public function run(): void
    {
        WebsiteSetting::instance();

        if (WebsiteStat::count() === 0) {
            $stats = [
                ['value' => 15, 'label_ar' => 'سنة خبرة', 'label_en' => 'years of experience'],
                ['value' => 120, 'label_ar' => 'منتج في الكتالوج', 'label_en' => 'catalog products'],
                ['value' => 50, 'label_ar' => 'موزع معتمد', 'label_en' => 'certified distributors'],
                ['value' => 500, 'label_ar' => 'سباك مسجل', 'label_en' => 'registered plumbers'],
                ['value' => 100, 'suffix' => '%', 'label_ar' => 'تتبع النقاط', 'label_en' => 'points traceability'],
            ];

            foreach ($stats as $i => $stat) {
                WebsiteStat::create([...$stat, 'sort_order' => $i + 1]);
            }
        }

        if (WebsiteService::count() === 0) {
            $services = [
                ['icon' => 'ti-package', 'title_ar' => 'تصنيع بالمواصفات', 'subtitle_en' => 'CUSTOM MANUFACTURING', 'description_ar' => 'نصنع أنابيب ومواد سباكة بحسب المواصفات المطلوبة — للمشاريع الكبيرة والمناقصات الحكومية.'],
                ['icon' => 'ti-truck', 'title_ar' => 'توزيع وطني', 'subtitle_en' => 'NATIONWIDE DISTRIBUTION', 'description_ar' => 'شبكة توزيع تغطي كل المدن الليبية — طرابلس، بنغازي، مصراتة، سبها، البيضاء والزاوية.'],
                ['icon' => 'ti-certificate', 'title_ar' => 'شهادات الجودة', 'subtitle_en' => 'QUALITY CERTIFICATION', 'description_ar' => 'جميع منتجاتنا مطابقة للمواصفات الليبية والأوروبية مع شهادات اختبار معتمدة.'],
                ['icon' => 'ti-tool', 'title_ar' => 'دعم فني متخصص', 'subtitle_en' => 'TECHNICAL SUPPORT', 'description_ar' => 'فريق فني متخصص يساعد السباكين والمقاولين في اختيار المواد المناسبة لكل مشروع.'],
                ['icon' => 'ti-star', 'title_ar' => 'نظام المكافآت', 'subtitle_en' => 'LOYALTY REWARDS', 'description_ar' => 'نظام نقاط متكامل يكافئ السباكين على كل عملية شراء — تحول تلقائياً لفلوس في محافظهم.'],
                ['icon' => 'ti-school', 'title_ar' => 'تدريب وتأهيل', 'subtitle_en' => 'TRAINING PROGRAMS', 'description_ar' => 'برامج تدريبية دورية للسباكين والموزعين على أحدث تقنيات السباكة واستخدام منتجاتنا.'],
            ];

            foreach ($services as $i => $service) {
                WebsiteService::create([...$service, 'sort_order' => $i + 1]);
            }
        }

        if (Slider::forHome()->count() === 0) {
            $slides = [
                [
                    'tag' => 'MG Plastic — Since 2010',
                    'title' => "مصنع أدوات السباكة\nالأول في ليبيا",
                    'description' => 'نصنع ونوزع أنابيب PVC ومواد السباكة بأعلى معايير الجودة الليبية والدولية — شبكة توزيع تمتد لكل ربوع ليبيا.',
                    'cta_primary_text' => 'تصفح المنتجات',
                    'cta_primary_url' => '#catalog',
                    'cta_secondary_text' => 'عن الشركة',
                    'cta_secondary_url' => '#about',
                    'background_style' => 'background:linear-gradient(135deg,#0d2d6e 0%,#1a56db 100%)',
                ],
                [
                    'tag' => 'Points Distribution System',
                    'title' => "نظام النقاط الأول\nلسباكي ليبيا",
                    'description' => 'اكسب نقاط من كل عملية شراء — تتحول مباشرة لفلوس في محفظتك الرقمية. نظام شفاف وآمن من المصنع للسباك.',
                    'cta_primary_text' => 'اعرف أكثر',
                    'cta_primary_url' => '#points',
                    'cta_secondary_text' => 'انضم الآن',
                    'cta_secondary_url' => '#register',
                    'background_style' => 'background:linear-gradient(135deg,#064e3b 0%,#059669 100%)',
                ],
                [
                    'tag' => 'Product Catalog 2025',
                    'title' => "كتالوج منتجات\nمتكامل ومتنوع",
                    'description' => 'أنابيب PVC، محابس، وصلات، سيفونات — كل ما يحتاجه مشروع السباكة في مكان واحد بمواصفات ليبية وأوروبية.',
                    'cta_primary_text' => 'تحميل الكتالوج',
                    'cta_primary_url' => '#catalog',
                    'cta_secondary_text' => 'تواصل معنا',
                    'cta_secondary_url' => '#contact',
                    'background_style' => 'background:linear-gradient(135deg,#1a3a6e 0%,#0891b2 100%)',
                ],
                [
                    'tag' => 'Network of Partners',
                    'title' => "شبكة موزعين\nفي كل ليبيا",
                    'description' => 'نعمل مع أكثر من ٥٠ موزع جملة وتاجر قطاعي في طرابلس وبنغازي ومصراتة وكل المدن الليبية.',
                    'cta_primary_text' => 'انضم كموزع',
                    'cta_primary_url' => '#register',
                    'cta_secondary_text' => 'موقعنا',
                    'cta_secondary_url' => '#contact',
                    'background_style' => 'background:linear-gradient(135deg,#78350f 0%,#d97706 100%)',
                ],
            ];

            foreach ($slides as $i => $slide) {
                Slider::create([
                    ...$slide,
                    'type' => 'home',
                    'image' => 'sliders/slide'.($i + 1).'.jpg',
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ]);
            }
        }
    }
}

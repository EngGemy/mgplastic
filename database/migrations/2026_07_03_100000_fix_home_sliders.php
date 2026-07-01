<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $fakeImages = [
            'sliders/slide1.jpg',
            'sliders/slide2.jpg',
            'sliders/slide3.jpg',
            'sliders/slide4.jpg',
        ];

        DB::table('sliders')
            ->whereIn('image', $fakeImages)
            ->update(['image' => '']);

        $templates = [
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

        $homeSliders = DB::table('sliders')
            ->where('type', 'home')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($homeSliders as $index => $slider) {
            if (filled($slider->title)) {
                continue;
            }

            $template = $templates[$index % count($templates)];

            DB::table('sliders')->where('id', $slider->id)->update([
                ...$template,
                'type' => 'home',
                'sort_order' => $slider->sort_order ?: ($index + 1),
            ]);
        }
    }

    public function down(): void
    {
        // Data migration — no rollback.
    }
};

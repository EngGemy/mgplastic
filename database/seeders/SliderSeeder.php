<?php

namespace Database\Seeders;

use App\Models\Slider;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SliderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Slider::forHome()->exists()) {
            return;
        }

        $slides = [
            [
                'tag' => 'MG Plastic — Since 2010',
                'title' => "مصنع أدوات السباكة\nالأول في ليبيا",
                'description' => 'نصنع ونوزع أنابيب PVC ومواد السباكة بأعلى معايير الجودة الليبية والدولية.',
                'background_style' => 'background:linear-gradient(135deg,#0d2d6e 0%,#1a56db 100%)',
            ],
            [
                'tag' => 'Points Distribution System',
                'title' => "نظام النقاط الأول\nلسباكي ليبيا",
                'description' => 'اكسب نقاط من كل عملية شراء — تتحول مباشرة لفلوس في محفظتك الرقمية.',
                'background_style' => 'background:linear-gradient(135deg,#064e3b 0%,#059669 100%)',
            ],
            [
                'tag' => 'Product Catalog 2025',
                'title' => "كتالوج منتجات\nمتكامل ومتنوع",
                'description' => 'أنابيب PVC، محابس، وصلات، سيفونات — كل ما يحتاجه مشروع السباكة.',
                'background_style' => 'background:linear-gradient(135deg,#1a3a6e 0%,#0891b2 100%)',
            ],
        ];

        foreach ($slides as $i => $slide) {
            Slider::create([
                ...$slide,
                'type' => 'home',
                'image' => '',
                'sort_order' => $i + 1,
                'is_active' => true,
            ]);
        }
    }
}

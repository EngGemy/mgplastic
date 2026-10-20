<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            ['key' => 'first_order', 'name_ar' => 'أول طلب', 'name_en' => 'First order', 'description_ar' => 'أول توزيع مؤكد', 'tier' => 'common', 'icon' => 'star', 'rule' => ['type' => 'first_order', 'min' => 1], 'sort_order' => 10],
            ['key' => 'orders_10', 'name_ar' => '10 طلبات', 'name_en' => '10 orders', 'description_ar' => '10 توزيعات مؤكدة', 'tier' => 'common', 'icon' => 'star', 'rule' => ['type' => 'orders_min', 'min' => 10], 'sort_order' => 20],
            ['key' => 'orders_50', 'name_ar' => '50 طلبًا', 'name_en' => '50 orders', 'description_ar' => '50 توزيعًا مؤكدًا', 'tier' => 'rare', 'icon' => 'medal', 'rule' => ['type' => 'orders_min', 'min' => 50], 'sort_order' => 30],
            ['key' => 'orders_100', 'name_ar' => '100 طلب', 'name_en' => '100 orders', 'description_ar' => '100 توزيع مؤكد', 'tier' => 'epic', 'icon' => 'trophy', 'rule' => ['type' => 'orders_min', 'min' => 100], 'sort_order' => 40],
            ['key' => 'points_1k', 'name_ar' => 'ألف نقطة', 'name_en' => '1k points', 'description_ar' => 'رصيد 1000 نقطة', 'tier' => 'common', 'icon' => 'coin', 'rule' => ['type' => 'points_min', 'min' => 1000], 'sort_order' => 50],
            ['key' => 'points_10k', 'name_ar' => 'عشرة آلاف نقطة', 'name_en' => '10k points', 'description_ar' => 'رصيد 10000 نقطة', 'tier' => 'rare', 'icon' => 'coin', 'rule' => ['type' => 'points_min', 'min' => 10000], 'sort_order' => 60],
            ['key' => 'week_streak_4', 'name_ar' => 'سلسلة 4 أسابيع', 'name_en' => '4-week streak', 'description_ar' => 'نشاط متواصل 4 أسابيع', 'tier' => 'common', 'icon' => 'fire', 'rule' => ['type' => 'streak_min', 'min' => 4], 'sort_order' => 70],
            ['key' => 'week_streak_8', 'name_ar' => 'سلسلة 8 أسابيع', 'name_en' => '8-week streak', 'description_ar' => 'نشاط متواصل 8 أسابيع', 'tier' => 'rare', 'icon' => 'fire', 'rule' => ['type' => 'streak_min', 'min' => 8], 'sort_order' => 80],
            ['key' => 'week_streak_12', 'name_ar' => 'سلسلة 12 أسبوعًا', 'name_en' => '12-week streak', 'description_ar' => 'نشاط متواصل 12 أسبوعًا', 'tier' => 'epic', 'icon' => 'fire', 'rule' => ['type' => 'streak_min', 'min' => 12], 'sort_order' => 90],
            ['key' => 'no_returns_30d', 'name_ar' => 'بدون مرتجعات', 'name_en' => 'No returns', 'description_ar' => '30 يومًا بلا مرتجعات مقبولة', 'tier' => 'rare', 'icon' => 'shield', 'rule' => ['type' => 'no_returns_30d'], 'sort_order' => 100],
            ['key' => 'early_bird', 'name_ar' => 'الطائر المبكر', 'name_en' => 'Early bird', 'description_ar' => '5 طلبات قبل الساعة 9 صباحًا', 'tier' => 'rare', 'icon' => 'sun', 'rule' => ['type' => 'early_bird', 'min' => 5], 'sort_order' => 110],
            ['key' => 'mg_loyal', 'name_ar' => 'وفيّ لـ MG', 'name_en' => 'MG loyal', 'description_ar' => '80% من مشترياتك منتجات MG بنقاط', 'tier' => 'epic', 'icon' => 'heart', 'rule' => ['type' => 'mg_loyal', 'min' => 80], 'sort_order' => 120],
            ['key' => 'big_basket', 'name_ar' => 'سلة كبيرة', 'name_en' => 'Big basket', 'description_ar' => 'طلب واحد فوق الحد المعيّن', 'tier' => 'rare', 'icon' => 'basket', 'rule' => ['type' => 'big_basket', 'min' => 50], 'sort_order' => 130],
            ['key' => 'warranty_first', 'name_ar' => 'أول ضمان', 'name_en' => 'First warranty', 'description_ar' => 'إصدار أول ضمان رسمي', 'tier' => 'rare', 'icon' => 'shield', 'rule' => ['type' => 'warranty_min', 'min' => 1], 'sort_order' => 140],
            ['key' => 'warranty_10', 'name_ar' => '10 ضمانات', 'name_en' => '10 warranties', 'description_ar' => 'إصدار 10 ضمانات', 'tier' => 'epic', 'icon' => 'shield', 'rule' => ['type' => 'warranty_min', 'min' => 10], 'sort_order' => 150],
            ['key' => 'trusted_pro', 'name_ar' => 'محترف موثوق', 'name_en' => 'Trusted pro', 'description_ar' => 'مستوى ذهبي أو أعلى', 'tier' => 'epic', 'icon' => 'badge', 'rule' => ['type' => 'level_min', 'min' => 'gold'], 'sort_order' => 160],
            ['key' => 'diamond_pro', 'name_ar' => 'محترف ماسي', 'name_en' => 'Diamond pro', 'description_ar' => 'وصول للمستوى الماسي', 'tier' => 'legendary', 'icon' => 'diamond', 'rule' => ['type' => 'level_min', 'min' => 'diamond'], 'sort_order' => 170],
        ];

        foreach ($badges as $row) {
            Badge::query()->updateOrCreate(
                ['key' => $row['key']],
                array_merge($row, ['is_active' => true])
            );
        }
    }
}

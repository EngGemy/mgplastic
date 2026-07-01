<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EventCategory;

class EventCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name_en' => 'Workshop', 'name_ar' => 'ورشة عمل'],
            ['name_en' => 'Conference', 'name_ar' => 'مؤتمر'],
            ['name_en' => 'Seminar', 'name_ar' => 'ندوة'],
            ['name_en' => 'Exhibition', 'name_ar' => 'معرض'],
        ];

        foreach ($categories as $category) {
            EventCategory::create($category);
        }
    }
}

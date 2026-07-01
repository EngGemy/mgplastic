<?php

namespace Database\Seeders;

use App\Models\Privacy;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PrivacySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Privacy::create([
            'slug' => 'mg-privacy',
            'en' => ['title' => 'Privacy Policy', 'content' => 'English Privacy...'],
            'ar' => ['title' => 'سياسة الخصوصية', 'content' => 'الخصوصية بالعربية...'],
        ]);
    }
}

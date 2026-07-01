<?php

namespace Database\Seeders;

use App\Models\SocialMedia;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SocialMediaSeeder extends Seeder

{
    public function run(): void
    {
        SocialMedia::create([
            'platform' => 'facebook',
            'url' => 'https://facebook.com/mg',
            'en' => ['name' => 'Facebook'],
            'ar' => ['name' => 'فيسبوك'],
        ]);

        SocialMedia::create([
            'platform' => 'instagram',
            'url' => 'https://instagram.com/mg',
            'en' => ['name' => 'Instagram'],
            'ar' => ['name' => 'انستجرام'],
        ]);
    }
}

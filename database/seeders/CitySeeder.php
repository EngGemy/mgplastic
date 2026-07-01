<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\City;
use App\Models\Country;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $libya = Country::where('name_en', 'Libya')->first();
        if ($libya) {
            City::create(['country_id' => $libya->id, 'name_en' => 'Tripoli', 'name_ar' => 'طرابلس']);
            City::create(['country_id' => $libya->id, 'name_en' => 'Benghazi', 'name_ar' => 'بنغازي']);
        }
    }
}



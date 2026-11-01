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
            City::query()->updateOrCreate(
                ['country_id' => $libya->id, 'name_en' => 'Tripoli'],
                ['name_ar' => 'طرابلس']
            );
            City::query()->updateOrCreate(
                ['country_id' => $libya->id, 'name_en' => 'Benghazi'],
                ['name_ar' => 'بنغازي']
            );
            City::query()->updateOrCreate(
                ['country_id' => $libya->id, 'name_en' => 'Misrata'],
                ['name_ar' => 'مصراتة']
            );
        }

        $this->call(ExhibitionCountriesSeeder::class);
    }
}



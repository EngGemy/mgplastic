<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Country;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        Country::query()->updateOrCreate(['name_en' => 'Libya'], ['name_ar' => 'ليبيا']);
        Country::query()->updateOrCreate(['name_en' => 'Egypt'], ['name_ar' => 'مصر']);
        Country::query()->updateOrCreate(['name_en' => 'Morocco'], ['name_ar' => 'المغرب']);
        Country::query()->updateOrCreate(['name_en' => 'Senegal'], ['name_ar' => 'السنغال']);
    }
}

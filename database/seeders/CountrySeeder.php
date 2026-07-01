<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Country;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $libya = Country::create(['name_en' => 'Libya', 'name_ar' => 'ليبيا']);
        $egypt = Country::create(['name_en' => 'Egypt', 'name_ar' => 'مصر']);
    }
}

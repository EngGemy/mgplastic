<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use Illuminate\Database\Seeder;

/**
 * Countries/cities for international exhibitions (Morocco, Senegal).
 * Safe to re-run (updateOrCreate).
 */
class ExhibitionCountriesSeeder extends Seeder
{
    public function run(): void
    {
        $morocco = Country::query()->updateOrCreate(
            ['name_en' => 'Morocco'],
            ['name_ar' => 'المغرب']
        );

        $senegal = Country::query()->updateOrCreate(
            ['name_en' => 'Senegal'],
            ['name_ar' => 'السنغال']
        );

        $cities = [
            $morocco->id => [
                ['name_en' => 'El Jadida', 'name_ar' => 'الجديدة'],
                ['name_en' => 'Casablanca', 'name_ar' => 'الدار البيضاء'],
                ['name_en' => 'Rabat', 'name_ar' => 'الرباط'],
                ['name_en' => 'Marrakech', 'name_ar' => 'مراكش'],
                ['name_en' => 'Tangier', 'name_ar' => 'طنجة'],
                ['name_en' => 'Fes', 'name_ar' => 'فاس'],
                ['name_en' => 'Agadir', 'name_ar' => 'أكادير'],
            ],
            $senegal->id => [
                ['name_en' => 'Dakar', 'name_ar' => 'داكار'],
                ['name_en' => 'Thiès', 'name_ar' => 'تييس'],
                ['name_en' => 'Saint-Louis', 'name_ar' => 'سان لويس'],
            ],
        ];

        foreach ($cities as $countryId => $list) {
            foreach ($list as $city) {
                City::query()->updateOrCreate(
                    [
                        'country_id' => $countryId,
                        'name_en' => $city['name_en'],
                    ],
                    ['name_ar' => $city['name_ar']]
                );
            }
        }
    }
}

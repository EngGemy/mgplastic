<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();
        $this->call([
            CountrySeeder::class,
            CitySeeder::class,
            UserSeeder::class,
            TermsConditionSeeder::class,
            PrivacySeeder::class,
            SocialMediaSeeder::class,
            SliderSeeder::class,
            WebsiteContentSeeder::class,
            PlumberStoreSeeder::class,

            EventCategorySeeder::class,
            EventSeeder::class,
            BlogCategorySeeder::class,
            BlogSeeder::class,
            ProductCatalogSeeder::class,
            SystemLabelSeeder::class,
            PointsSystemDemoSeeder::class,

        ]);


    }
}

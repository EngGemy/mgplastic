<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\City;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        $category = EventCategory::first();
        $city = City::first();

        if ($category && $city) {
            Event::create([
                'category_id' => $category->id,
                'city_id' => $city->id,
                'image' => 'events/sample.jpg',
                'event_date' => now()->toDateString(),
                'event_time' => now()->format('H:i'),
                'latitude' => 32.8872,
                'longitude' => 13.1913,
                'en' => [
                    'title' => 'Libya Plumbing Expo',
                    'description' => 'The largest plumbing industry exhibition in Libya.'
                ],
                'ar' => [
                    'title' => 'معرض السباكة في ليبيا',
                    'description' => 'أكبر معرض لصناعة السباكة في ليبيا.'
                ]
            ]);
        }
    }
}

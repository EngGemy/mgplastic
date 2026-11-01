<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\Event;
use App\Models\EventCategory;
use Database\Seeders\ExhibitionCountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventExhibitionLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhibition_seeder_adds_morocco_and_senegal_cities(): void
    {
        $this->seed(ExhibitionCountriesSeeder::class);

        $this->assertDatabaseHas('countries', ['name_en' => 'Morocco', 'name_ar' => 'المغرب']);
        $this->assertDatabaseHas('countries', ['name_en' => 'Senegal', 'name_ar' => 'السنغال']);
        $this->assertDatabaseHas('cities', ['name_en' => 'El Jadida', 'name_ar' => 'الجديدة']);
        $this->assertDatabaseHas('cities', ['name_en' => 'Dakar', 'name_ar' => 'داكار']);
    }

    public function test_event_api_returns_country_name_for_morocco_city(): void
    {
        $this->seed(ExhibitionCountriesSeeder::class);

        $city = City::query()->where('name_en', 'El Jadida')->firstOrFail();
        $category = EventCategory::query()->create([
            'name_en' => 'Exhibition',
            'name_ar' => 'معرض',
        ]);

        $event = Event::query()->create([
            'category_id' => $city->id ? $category->id : $category->id,
            'city_id' => $city->id,
            'address' => 'مركز المعارض محمد السادس — الجديدة، المغرب',
            'event_date' => '2026-11-25',
            'event_time' => '10:00',
            'latitude' => 33.2316,
            'longitude' => -8.5007,
            'en' => ['title' => 'SIB 2026', 'description' => 'Morocco'],
            'ar' => ['title' => 'صالون البناء', 'description' => 'المغرب'],
        ]);

        $this->getJson('/api/v1/events/'.$event->id, [
            'Accept-Language' => 'ar',
        ])
            ->assertOk()
            ->assertJsonPath('data.city_name', 'الجديدة')
            ->assertJsonPath('data.country_name', 'المغرب')
            ->assertJsonPath('data.latitude', 33.2316)
            ->assertJsonPath('data.longitude', -8.5007);
    }

    public function test_fix_exhibition_locations_command_moves_libya_pin_to_el_jadida(): void
    {
        $this->seed(ExhibitionCountriesSeeder::class);

        $misrataCountry = Country::query()->updateOrCreate(
            ['name_en' => 'Libya'],
            ['name_ar' => 'ليبيا']
        );
        $misrata = City::query()->updateOrCreate(
            ['country_id' => $misrataCountry->id, 'name_en' => 'Misrata'],
            ['name_ar' => 'مصراتة']
        );
        $elJadida = City::query()->where('name_en', 'El Jadida')->firstOrFail();

        $category = EventCategory::query()->create([
            'name_en' => 'Exhibition',
            'name_ar' => 'معرض',
        ]);

        $event = Event::query()->create([
            'category_id' => $category->id,
            'city_id' => $misrata->id,
            'address' => 'مركز المعارض محمد السادس – الجديدة، المغرب',
            'event_date' => '2026-11-25',
            'event_time' => '10:00',
            'latitude' => 32.8872,
            'longitude' => 13.1913,
            'en' => ['title' => 'MG Morocco 2026', 'description' => 'El Jadida'],
            'ar' => ['title' => 'MG المغرب 2026', 'description' => 'الجديدة المغرب'],
        ]);

        $this->artisan('events:fix-exhibition-locations')
            ->assertSuccessful();

        $event->refresh();
        $this->assertSame((int) $elJadida->id, (int) $event->city_id);
        $this->assertEqualsWithDelta(33.2316, (float) $event->latitude, 0.01);
        $this->assertEqualsWithDelta(-8.5007, (float) $event->longitude, 0.01);
    }
}

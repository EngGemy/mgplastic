<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventExhibitionLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_api_returns_typed_city_and_country(): void
    {
        $category = EventCategory::query()->create([
            'name_en' => 'Exhibition',
            'name_ar' => 'معرض',
        ]);

        $event = Event::query()->create([
            'category_id' => $category->id,
            'city_id' => null,
            'location_city' => 'الجديدة',
            'location_country' => 'المغرب',
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

    public function test_create_event_accepts_free_text_location(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);
        Sanctum::actingAs($admin);

        $category = EventCategory::query()->create([
            'name_en' => 'Exhibition',
            'name_ar' => 'معرض',
        ]);

        $this->postJson('/api/v1/events', [
            'category_id' => $category->id,
            'location_city' => 'داكار',
            'location_country' => 'السنغال',
            'address' => 'Dakar Expo',
            'event_date' => '2026-12-01',
            'event_time' => '09:00',
            'latitude' => 14.7167,
            'longitude' => -17.4677,
            'title_en' => 'Senegal Fair',
            'title_ar' => 'معرض السنغال',
            'description_en' => 'Dakar',
            'description_ar' => 'داكار',
        ])
            ->assertCreated()
            ->assertJsonPath('data.city_name', 'داكار')
            ->assertJsonPath('data.country_name', 'السنغال');

        $this->assertDatabaseHas('events', [
            'location_city' => 'داكار',
            'location_country' => 'السنغال',
        ]);
    }
}

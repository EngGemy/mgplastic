<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CriticalApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_claims(): void
    {
        Claim::query()->create([
            'name' => 'Ali',
            'email' => 'ali@example.com',
            'phone' => '0911111111',
            'message' => 'secret claim',
        ]);

        $this->getJson('/api/v1/claims')->assertUnauthorized();
    }

    public function test_non_admin_cannot_list_claims(): void
    {
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_phone_verified' => true,
            'is_active' => true,
        ]);

        $this->withToken($plumber->createToken('t')->plainTextToken)
            ->getJson('/api/v1/claims')
            ->assertForbidden();
    }

    public function test_guest_cannot_create_slider(): void
    {
        Storage::fake('public');

        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/sliders', [
                'type' => 'home',
                'image' => UploadedFile::fake()->image('banner.jpg'),
            ])
            ->assertUnauthorized();
    }

    public function test_guest_cannot_create_event(): void
    {
        $this->postJson('/api/v1/events', [
            'category_id' => 1,
            'location_city' => 'Tripoli',
            'location_country' => 'Libya',
            'event_date' => now()->toDateString(),
            'event_time' => '10:00',
            'address' => 'Somewhere',
            'title_en' => 'Hack',
            'title_ar' => 'اختراق',
        ])->assertUnauthorized();
    }

    public function test_guest_cannot_create_or_approve_blog(): void
    {
        $this->postJson('/api/v1/blogs', [
            'category_id' => 1,
            'title' => 'x',
            'description' => 'y',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/blogs/1/approve')->assertUnauthorized();
    }

    public function test_stranger_cannot_read_invoice_items(): void
    {
        $owner = User::factory()->create([
            'role' => 'plumber',
            'is_phone_verified' => true,
            'is_active' => true,
        ]);
        $stranger = User::factory()->create([
            'role' => 'plumber',
            'is_phone_verified' => true,
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'plumber_id' => $owner->id,
        ]);

        $this->assertTrue($invoice->fresh()->canBeViewedBy($owner->fresh()));
        $this->assertFalse($invoice->fresh()->canBeViewedBy($stranger->fresh()));

        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/invoices/'.$invoice->id.'/items')->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/invoices/'.$invoice->id.'/items')->assertOk();
    }

    public function test_retail_trader_cannot_access_legacy_plumber_wallet(): void
    {
        $trader = User::factory()->create([
            'role' => 'retail_trader',
            'is_phone_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        $this->withToken($trader->createToken('t')->plainTextToken)
            ->getJson('/api/v1/plumber/wallet')
            ->assertForbidden();
    }

    public function test_register_plumber_rejects_vendor_role_escalation(): void
    {
        $this->postJson('/api/v1/auth/register-plumber', [
            'name' => 'Shop',
            'phone' => '0912999888',
            'country_id' => 1,
            'city_id' => 1,
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'role' => 'vendor',
        ])->assertStatus(422);
    }

    public function test_otp_locks_after_max_failed_attempts(): void
    {
        $user = User::factory()->create([
            'phone' => '0912333444',
            'role' => 'plumber',
            'is_phone_verified' => false,
            'is_active' => true,
            'otp_code' => Hash::make('123456'),
            'otp_expires_at' => now()->addMinutes(5),
            'otp_attempts' => 5,
        ]);

        $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => $user->phone,
            'otp' => '000000',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'otp_locked');
    }

    public function test_guest_can_still_read_public_catalog_surfaces(): void
    {
        $this->getJson('/api/v1/sliders')->assertOk();
        $this->getJson('/api/v1/mobile/home/layout?role=guest')->assertOk();
    }
}

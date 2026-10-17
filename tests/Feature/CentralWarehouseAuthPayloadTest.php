<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CentralWarehouseAuthPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_me_include_is_central_warehouse(): void
    {
        $warehouse = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_central_warehouse' => true,
            'is_approved' => true,
            'is_active' => true,
            'is_phone_verified' => true,
            'phone' => '0910000001',
            'password' => 'Warehouse@2026',
        ]);

        $normal = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_central_warehouse' => false,
            'is_approved' => true,
            'is_active' => true,
            'is_phone_verified' => true,
            'phone' => '0910000002',
            'password' => 'Password@2026',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'phone' => '0910000001',
            'password' => 'Warehouse@2026',
        ])
            ->assertOk()
            ->assertJsonPath('user.is_central_warehouse', true)
            ->assertJsonPath('user.role', 'wholesale_distributor');

        Sanctum::actingAs($warehouse);
        $this->getJson('/api/v1/mobile/me')
            ->assertOk()
            ->assertJsonPath('data.is_central_warehouse', true)
            ->assertJsonPath('data.role', 'wholesale_distributor');

        Sanctum::actingAs($normal);
        $this->getJson('/api/v1/mobile/me')
            ->assertOk()
            ->assertJsonPath('data.is_central_warehouse', false);
    }
}

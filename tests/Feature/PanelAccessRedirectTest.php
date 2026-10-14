<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_other_roles_leave_the_trader_panel_instead_of_a_blank_403(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_approved' => true]);
        $distributor = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $trader = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $this->get('/trader')->assertRedirect('/trader/login');

        $this->actingAs($admin)
            ->get('/trader')
            ->assertRedirect('/admin');

        $this->actingAs($distributor)
            ->get('/trader')
            ->assertRedirect('/distributor');

        $this->actingAs($trader)
            ->get('/trader')
            ->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Pages\DecisionReports;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use App\Services\DecisionReportsService;
use App\Support\AdminPanelPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DecisionReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_returns_decision_sections(): void
    {
        User::factory()->create(['role' => 'super_admin']);

        $snap = app(DecisionReportsService::class)->snapshot(30);

        $this->assertSame(30, $snap['window_days']);
        $this->assertArrayHasKey('executive', $snap);
        $this->assertArrayHasKey('network', $snap);
        $this->assertArrayHasKey('orders', $snap);
        $this->assertArrayHasKey('points', $snap);
        $this->assertArrayHasKey('finance', $snap);
        $this->assertArrayHasKey('inventory', $snap);
        $this->assertArrayHasKey('alerts', $snap);
        $this->assertArrayHasKey('trends', $snap);
        $this->assertNotEmpty($snap['executive']);
        $this->assertNotEmpty($snap['alerts']);
    }

    public function test_gemini_context_is_compact(): void
    {
        $ctx = app(DecisionReportsService::class)->geminiContext(7);

        $this->assertSame(7, $ctx['window_days']);
        $this->assertArrayHasKey('executive', $ctx);
        $this->assertArrayHasKey('alerts', $ctx);
        $this->assertArrayNotHasKey('trends', $ctx);
    }

    public function test_gemini_disabled_without_api_key(): void
    {
        config(['services.gemini.key' => null]);

        $client = app(GeminiClient::class);
        $this->assertFalse($client->enabled());

        $result = $client->ask('ما الوضع؟', ['executive' => []]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('GEMINI_API_KEY', (string) $result['error']);
    }

    public function test_admin_can_open_decision_reports_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        Livewire::test(DecisionReports::class)
            ->assertSuccessful()
            ->assertSee('مركز صناعة القرار')
            ->assertSee('اسأل Gemini');
    }

    public function test_decision_reports_route_is_reachable(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get('/'.AdminPanelPath::segment().'/decision-reports')
            ->assertOk();
    }
}

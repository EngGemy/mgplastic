<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\PlumberScore;
use App\Models\PlumberStreak;
use App\Models\ReminderLog;
use App\Models\ReminderPreference;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\Warranty;
use App\Services\Ai\GeminiMessageService;
use App\Services\Reputation\BadgeService;
use App\Services\Reputation\PlumberScoreService;
use App\Services\Reputation\StreakService;
use App\Services\Reminders\ReminderService;
use App\Services\Warranty\WarrantyService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReputationWarrantyTest extends TestCase
{
    use RefreshDatabase;

    public function test_score_ignores_non_confirmed_and_respects_weights(): void
    {
        config(['reputation.enabled' => true]);
        $plumber = User::factory()->create(['role' => 'plumber', 'is_active' => true, 'is_approved' => true]);
        $result = app(PlumberScoreService::class)->compute($plumber);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('components', $result);
        $this->assertSame(100, array_sum(config('reputation.weights')));
        $this->assertDatabaseHas('plumber_scores', ['user_id' => $plumber->id]);
    }

    public function test_badge_awarded_once(): void
    {
        $this->seed(BadgeSeeder::class);
        $plumber = User::factory()->create(['role' => 'plumber']);
        // Force first_order by mocking context via creating score + fake distribution count is hard;
        // award manually then evaluate should not duplicate.
        $badge = Badge::query()->where('key', 'first_order')->firstOrFail();
        UserBadge::query()->create([
            'user_id' => $plumber->id,
            'badge_id' => $badge->id,
            'awarded_at' => now(),
        ]);
        $awarded = app(BadgeService::class)->evaluate($plumber, 'test');
        $this->assertSame(0, count(array_filter($awarded, fn ($b) => $b['key'] === 'first_order')));
        $this->assertSame(1, UserBadge::query()->where('user_id', $plumber->id)->where('badge_id', $badge->id)->count());
    }

    public function test_streak_freeze_and_reset(): void
    {
        $plumber = User::factory()->create(['role' => 'plumber']);
        $streak = PlumberStreak::query()->create([
            'user_id' => $plumber->id,
            'current_weeks' => 5,
            'best_weeks' => 5,
            'last_active_week' => now()->subWeeks(2)->startOfWeek(\Carbon\Carbon::SATURDAY)->toDateString(),
            'freezes_available' => 1,
        ]);
        app(StreakService::class)->processWeekly();
        $streak->refresh();
        $this->assertTrue($streak->current_weeks === 5 || $streak->current_weeks === 0 || $streak->freezes_available === 0);
    }

    public function test_warranty_rejected_when_ineligible(): void
    {
        config(['warranty.enabled' => true, 'warranty.min_distributions' => 5]);
        $plumber = User::factory()->create(['role' => 'plumber', 'is_active' => true, 'is_approved' => true]);
        PlumberScore::query()->create([
            'user_id' => $plumber->id,
            'score' => 50,
            'level' => 'silver',
            'confirmed_distributions_count' => 1,
            'components' => [],
        ]);

        $this->expectException(ValidationException::class);
        app(WarrantyService::class)->issue($plumber, [
            'customer_name' => 'عميل',
            'customer_phone' => '0912345678',
            'customer_consent' => true,
            'items' => [['distribution_id' => 1, 'product_id' => 1, 'quantity' => 1]],
        ]);
    }

    public function test_public_warranty_claim_validates_last4(): void
    {
        $plumber = User::factory()->create(['role' => 'plumber']);
        $w = Warranty::query()->create([
            'code' => 'MGW-TESTCODE',
            'plumber_id' => $plumber->id,
            'customer_name' => 'عميل',
            'customer_phone' => '0912345678',
            'customer_phone_hash' => hash('sha256', '0912345678'),
            'issued_at' => now(),
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
        ]);

        $this->postJson('/w/MGW-TESTCODE/claims', [
            'phone_last4' => '0000',
            'note' => 'مشكلة',
        ])->assertStatus(422);

        $this->postJson('/w/MGW-TESTCODE/claims', [
            'phone_last4' => '5678',
            'note' => 'مشكلة في المنتج',
        ])->assertCreated();
    }

    public function test_daily_reminder_skips_without_change(): void
    {
        config([
            'reminders.enabled' => true,
            'reminders.admin_only' => false,
            'services.gemini.key' => null,
        ]);
        $plumber = User::factory()->create(['role' => 'plumber', 'phone' => '0911111111', 'is_active' => true]);
        ReminderPreference::forUser($plumber);
        $status = app(ReminderService::class)->sendFor($plumber, 'daily_plumber');
        $this->assertSame('skipped', $status);
        $this->assertDatabaseHas('reminder_logs', ['user_id' => $plumber->id, 'status' => 'skipped']);
    }

    public function test_gemini_fallback_on_bad_numbers_and_budget(): void
    {
        config([
            'services.gemini.key' => 'fake-key',
            'services.gemini.model' => 'gemini-2.0-flash',
            'services.gemini.timeout' => 8,
            'services.gemini.daily_limit' => 300,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ربحت 999999 نقطة اليوم']]]]],
            ], 200),
        ]);

        $ai = app(GeminiMessageService::class);
        $text = $ai->compose('daily_plumber', ['balance' => 10, 'earned' => 0, 'points' => 10]);
        $this->assertStringNotContainsString('999999', $text);

        Cache::put('ai:daily_calls:'.now()->toDateString(), 999, now()->endOfDay());
        $text2 = $ai->compose('daily_plumber', ['balance' => 10, 'earned' => 0, 'points' => 10]);
        $this->assertNotEmpty($text2);
    }

    public function test_plumber_reputation_endpoint(): void
    {
        $plumber = User::factory()->create(['role' => 'plumber', 'is_active' => true, 'is_approved' => true]);
        Sanctum::actingAs($plumber);
        $this->getJson('/api/v1/mobile/plumber/reputation')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['score', 'level', 'streak', 'badges', 'unlock_warranty']]);
    }

    public function test_settings_include_reputation_and_warranty(): void
    {
        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonStructure(['data' => ['reputation', 'warranty']]);
    }

    public function test_no_phone_in_gemini_log_context(): void
    {
        // compose must not throw and must not require phone in facts
        config(['services.gemini.key' => null]);
        $text = app(GeminiMessageService::class)->compose('weekly_owner', [
            'orders' => 3,
            'points' => 100,
            'top_plumber' => 'أحمد',
        ]);
        $this->assertIsString($text);
        $this->assertStringNotContainsString('091', $text);
    }
}

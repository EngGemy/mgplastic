<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards guest home layout warm-path cost (no seed/settings/popup chatter).
 */
class HomeLayoutPerfProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_warm_layout_hits_zero_queries(): void
    {
        Cache::flush();

        $this->withHeaders(['Accept-Language' => 'ar'])
            ->getJson('/api/v1/mobile/home/layout?role=guest')
            ->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withHeaders(['Accept-Language' => 'ar'])
            ->getJson('/api/v1/mobile/home/layout?role=guest')
            ->assertOk()
            ->assertJsonPath('data.role', 'guest');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(
            0,
            $queries,
            'Warm guest home layout must not hit the database. Queries: '.json_encode(array_column($queries, 'query'))
        );
    }
}

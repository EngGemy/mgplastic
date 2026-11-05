<?php

namespace Tests\Feature;

use App\Models\OtaUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishTestOtaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishes_ios_and_android_test_bundles(): void
    {
        config(['app.url' => 'https://mg-plastic.test']);

        $this->artisan('ota:publish-test', [
            '--ios-build' => '10',
            '--android-build' => '13',
        ])->assertSuccessful();

        $ios = OtaUpdate::query()->where('platform', 'ios')->where('native_build', '10')->first();
        $android = OtaUpdate::query()->where('platform', 'android')->where('native_build', '13')->first();

        $this->assertNotNull($ios);
        $this->assertNotNull($android);
        $this->assertTrue($ios->is_active);
        $this->assertTrue($android->is_active);
        $this->assertTrue(Storage::disk('public')->exists($ios->file_path));
        $this->assertTrue(Storage::disk('public')->exists($android->file_path));

        $this->getJson('/api/v1/mobile/ota/check?platform=ios&native_build=10&bundle_version=0')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.version', $ios->version);

        $ios->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/mobile/ota/check?platform=ios&native_build=10&bundle_version=0')
            ->assertOk()
            ->assertJsonPath('data.update_available', false);
    }
}

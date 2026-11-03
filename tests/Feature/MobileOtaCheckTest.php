<?php

namespace Tests\Feature;

use App\Models\OtaUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileOtaCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://mg-plastic.test']);
        Cache::flush();
    }

    public function test_check_requires_query_params(): void
    {
        $this->getJson('/api/v1/mobile/ota/check')
            ->assertStatus(422);

        $this->getJson('/api/v1/mobile/ota/check?platform=windows&native_build=10&bundle_version=0')
            ->assertStatus(422);

        $this->getJson('/api/v1/mobile/ota/check?platform=ios&bundle_version=0')
            ->assertStatus(422);
    }

    public function test_check_returns_false_when_no_matching_update(): void
    {
        $this->seedReachableZip('ota/mg-ios-build10-v2.zip');

        OtaUpdate::query()->create([
            'platform' => 'ios',
            'native_build' => '10',
            'version' => 2,
            'file_path' => 'ota/mg-ios-build10-v2.zip',
            'is_active' => true,
        ]);

        // Wrong native_build
        $this->getJson('/api/v1/mobile/ota/check?platform=ios&native_build=11&bundle_version=0')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.update_available', false)
            ->assertJsonMissingPath('data.version');

        // Wrong platform
        $this->getJson('/api/v1/mobile/ota/check?platform=android&native_build=10&bundle_version=0')
            ->assertOk()
            ->assertJsonPath('data.update_available', false);
    }

    public function test_inactive_update_is_hidden(): void
    {
        $this->seedReachableZip('ota/mg-ios-build10-v2.zip');

        OtaUpdate::query()->create([
            'platform' => 'ios',
            'native_build' => '10',
            'version' => 2,
            'file_path' => 'ota/mg-ios-build10-v2.zip',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/mobile/ota/check?platform=ios&native_build=10&bundle_version=0')
            ->assertOk()
            ->assertJsonPath('data.update_available', false);
    }

    public function test_returns_highest_active_version_above_bundle(): void
    {
        $this->seedReachableZip('ota/mg-ios-build10-v1.zip');
        $this->seedReachableZip('ota/mg-ios-build10-v3.zip');

        OtaUpdate::query()->create([
            'platform' => 'ios',
            'native_build' => '10',
            'version' => 1,
            'file_path' => 'ota/mg-ios-build10-v1.zip',
            'is_active' => true,
        ]);

        OtaUpdate::query()->create([
            'platform' => 'ios',
            'native_build' => '10',
            'version' => 3,
            'file_path' => 'ota/mg-ios-build10-v3.zip',
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/v1/mobile/ota/check?platform=ios&native_build=10&bundle_version=0')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.version', 3);

        $url = $res->json('data.download_url');
        $this->assertIsString($url);
        $this->assertTrue(str_starts_with($url, 'https://'));
        $this->assertStringContainsString('mg-ios-build10-v3.zip', $url);

        // Exact types for the mobile client
        $this->assertTrue($res->json('data.update_available') === true);
        $this->assertIsInt($res->json('data.version'));
    }

    public function test_bundle_version_equal_or_higher_means_no_update(): void
    {
        $this->seedReachableZip('ota/mg-ios-build10-v2.zip');

        OtaUpdate::query()->create([
            'platform' => 'ios',
            'native_build' => '10',
            'version' => 2,
            'file_path' => 'ota/mg-ios-build10-v2.zip',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/mobile/ota/check?platform=ios&native_build=10&bundle_version=2')
            ->assertOk()
            ->assertJsonPath('data.update_available', false);

        $this->getJson('/api/v1/mobile/ota/check?platform=ios&native_build=10&bundle_version=5')
            ->assertOk()
            ->assertJsonPath('data.update_available', false);
    }

    public function test_prefers_active_lower_version_when_higher_is_disabled(): void
    {
        $this->seedReachableZip('ota/mg-ios-build10-v1.zip');
        $this->seedReachableZip('ota/mg-ios-build10-v2.zip');

        OtaUpdate::query()->create([
            'platform' => 'ios',
            'native_build' => '10',
            'version' => 1,
            'file_path' => 'ota/mg-ios-build10-v1.zip',
            'is_active' => true,
        ]);

        OtaUpdate::query()->create([
            'platform' => 'ios',
            'native_build' => '10',
            'version' => 2,
            'file_path' => 'ota/mg-ios-build10-v2.zip',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/mobile/ota/check?platform=ios&native_build=10&bundle_version=0')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.version', 1);
    }

    public function test_works_without_auth_token(): void
    {
        $this->seedReachableZip('ota/mg-android-build10-v1.zip');

        OtaUpdate::query()->create([
            'platform' => 'android',
            'native_build' => '10',
            'version' => 1,
            'file_path' => 'ota/mg-android-build10-v1.zip',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/mobile/ota/check?platform=android&native_build=10&bundle_version=0')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.version', 1);
    }

    private function seedReachableZip(string $relativePath): void
    {
        Storage::disk('public')->put($relativePath, 'PK fake-zip-content');

        $webPath = public_path('storage/'.$relativePath);
        $dir = dirname($webPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($webPath, 'PK fake-zip-content');
    }
}

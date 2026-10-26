<?php

namespace Tests\Feature;

use App\Models\HomeMobileSetting;
use App\Models\HomeProductType;
use App\Services\HomeMobileLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileHomeLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_layout_endpoint_returns_banner_and_titles(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $this->getJson('/api/v1/mobile/home/layout')
            ->assertOk()
            ->assertJsonPath('data.banner.title', 'منتجات MG')
            ->assertJsonPath('data.titles.main_sections', 'الأقسام الرئيسية')
            ->assertJsonStructure([
                'data' => [
                    'banner' => ['title', 'subtitle', 'cta_text', 'cta_link', 'is_active'],
                    'titles' => ['main_sections', 'new_products', 'stores'],
                    'product_types',
                ],
            ]);
    }

    public function test_updating_settings_changes_layout_api(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $settings = HomeMobileSetting::instance();
        $settings->update([
            'banner_title' => 'تشكيلة MG الجديدة',
            'main_sections_title' => 'أقسام القطع',
        ]);
        app(HomeMobileLayoutService::class)->forgetCache();

        $this->getJson('/api/v1/mobile/home/layout')
            ->assertOk()
            ->assertJsonPath('data.banner.title', 'تشكيلة MG الجديدة')
            ->assertJsonPath('data.titles.main_sections', 'أقسام القطع');
    }

    public function test_product_types_endpoint_reads_db_rows(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        HomeProductType::query()->where('key', 'tee')->update([
            'name' => 'وصلات T المعدّلة',
            'subtitle' => 'كل المقاسات',
        ]);
        app(HomeMobileLayoutService::class)->forgetCache();

        $types = app(\App\Services\ProductTypeService::class)->catalogForHome();
        $tee = collect($types)->firstWhere('key', 'tee');

        $this->assertNotNull($tee);
        $this->assertSame('وصلات T المعدّلة', $tee['name']);
        $this->assertSame('كل المقاسات', $tee['subtitle']);
    }

    public function test_settings_exposes_home_layout_url(): void
    {
        $this->getJson('/api/v1/mobile/settings')
            ->assertOk()
            ->assertJsonPath('data.home.layout', url('/api/v1/mobile/home/layout'));
    }
}

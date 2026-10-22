<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use App\Models\Slider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileHomeWholesaleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_types_and_filter_for_distributor(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
        ]);

        $cat = ProductCategory::query()->create(['slug' => 'european-test']);
        $cat->translateOrNew('ar')->name = 'حسب النظام الاوروربي';
        $cat->translateOrNew('ar')->description = '';
        $cat->save();

        $elbow = Product::factory()->create(['product_category_id' => $cat->id]);
        $elbow->translateOrNew('ar')->name = 'كوع 90 اختبار';
        $elbow->save();
        ProductTag::syncNamesOntoProduct($elbow, ['كوع']);

        $pipe = Product::factory()->create(['product_category_id' => $cat->id]);
        $pipe->translateOrNew('ar')->name = 'ماسورة مياه 32';
        $pipe->save();

        Sanctum::actingAs($wholesaler);

        $this->getJson('/api/v1/mobile/distributor/products/types')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonFragment(['key' => 'elbow', 'name' => 'أكواع'])
            ->assertJsonFragment(['key' => 'water_pipe']);

        $this->getJson('/api/v1/mobile/distributor/products?product_type=elbow')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $elbow->id)
            ->assertJsonPath('data.items.0.product_type', 'elbow')
            ->assertJsonPath('data.filters.product_type', 'elbow');

        $this->getJson('/api/v1/mobile/distributor/products/categories?systems=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'حسب النظام الاوروبي')
            ->assertJsonPath('data.items.0.short_description', 'مقاسات ومواصفات · EN Standard')
            ->assertJsonPath('data.items.0.badge', 'EN')
            ->assertJsonPath('data.items.0.icon_url', null);

        // General categories list also includes short_description + null image when missing
        $cat->forceFill(['image' => 'categories/missing-does-not-exist.jpg'])->save();

        $this->getJson('/api/v1/mobile/distributor/products/categories')
            ->assertOk()
            ->assertJsonPath('data.items.0.short_description', 'مقاسات ومواصفات · EN Standard')
            ->assertJsonPath('data.items.0.image_url', null);

        $this->getJson('/api/v1/products/categories')
            ->assertOk()
            ->assertJsonPath('data.0.short_description', 'مقاسات ومواصفات · EN Standard')
            ->assertJsonPath('data.0.image_url', null);
    }

    public function test_broken_storage_urls_become_null(): void
    {
        $cat = ProductCategory::query()->create([
            'slug' => 'gold-system',
            'image' => 'https://example.test/storage/categories/not-on-disk.jpg',
        ]);
        $cat->translateOrNew('ar')->name = 'مواسير الذهبي';
        $cat->translateOrNew('ar')->description = '';
        $cat->save();

        $this->assertNull($cat->resolvedImageUrl());
        $this->assertSame('مواسير النظام الذهبي', $cat->shortDescription());
    }

    public function test_home_highlights_and_slider_overlay_fields(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('sliders/home-hero.jpg', 'fake-image');

        // Ensure public/storage path is considered reachable in tests.
        $link = public_path('storage');
        if (! is_link($link) && ! is_dir($link)) {
            @mkdir($link, 0777, true);
        }
        @file_put_contents(public_path('storage/sliders/home-hero.jpg'), 'fake-image');

        Slider::query()->create([
            'type' => 'home',
            'image' => 'sliders/home-hero.jpg',
            'title' => 'مواسير MG',
            'description' => 'نظام أوروبي ونظام أمريكي',
            'cta_primary_text' => 'استعرض المنتجات',
            'cta_primary_url' => '#catalog',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Broken / empty image → omitted from API (no null image rows).
        Slider::query()->create([
            'type' => 'home',
            'image' => 'sliders/missing.jpg',
            'title' => 'بدون صورة',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $eu = ProductCategory::query()->create(['slug' => 'eu-pipes']);
        $eu->translateOrNew('ar')->name = 'حسب النظام الاوروبي';
        $eu->save();

        $this->getJson('/api/v1/mobile/home/highlights')
            ->assertOk()
            ->assertJsonFragment(['key' => 'projects', 'label' => 'المشاريع'])
            ->assertJsonFragment(['key' => 'sewage'])
            ->assertJsonFragment(['key' => 'drinking_water'])
            ->assertJsonPath('data.2.key', 'drinking_water')
            ->assertJsonPath('data.2.category_id', $eu->id);

        $this->getJson('/api/v1/sliders/home')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'مواسير MG')
            ->assertJsonPath('data.0.subtitle', 'نظام أوروبي ونظام أمريكي')
            ->assertJsonPath('data.0.button_text', 'استعرض المنتجات');
    }

    public function test_promo_banners_between_plumbers_stores_does_not_500(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        \App\Models\PromoBanner::query()->create([
            'title' => 'عرض تجريبي',
            'subtitle' => 'بين السباكين والمتاجر',
            'image_path' => 'promo-banners/missing.png',
            'section' => \App\Models\PromoBanner::SECTION_BETWEEN_PLUMBERS_STORES,
            'link_type' => 'product',
            'link_id' => 12,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/mobile/promo-banners?section=between_plumbers_stores')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.section', 'between_plumbers_stores')
            ->assertJsonPath('data.items.0.title', 'عرض تجريبي')
            ->assertJsonPath('data.items.0.image_url', null)
            ->assertJsonPath('data.items.0.link.type', 'product')
            ->assertJsonPath('data.items.0.link.id', 12);

        $this->getJson('/api/v1/mobile/promo-banners')
            ->assertOk()
            ->assertJsonPath('data.section', 'between_plumbers_stores');
    }
}

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
            ->assertJsonPath('data.items.0.short_description', 'مقاسات ومواصفات · EN Standard');
    }

    public function test_home_highlights_and_slider_overlay_fields(): void
    {
        Slider::query()->create([
            'type' => 'home',
            'image' => '',
            'title' => 'مواسير MG',
            'description' => 'نظام أوروبي ونظام أمريكي',
            'cta_primary_text' => 'استعرض المنتجات',
            'cta_primary_url' => '#catalog',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/v1/mobile/home/highlights')
            ->assertOk()
            ->assertJsonFragment(['key' => 'projects', 'label' => 'المشاريع'])
            ->assertJsonFragment(['key' => 'sewage'])
            ->assertJsonFragment(['key' => 'drinking_water']);

        $this->getJson('/api/v1/sliders/home')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'مواسير MG')
            ->assertJsonPath('data.0.subtitle', 'نظام أوروبي ونظام أمريكي')
            ->assertJsonPath('data.0.button_text', 'استعرض المنتجات');
    }
}

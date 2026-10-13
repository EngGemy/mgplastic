<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_can_be_filtered_by_search_and_tag(): void
    {
        $parent = ProductCategory::factory()->create();
        $parent->translateOrNew('ar')->name = 'صرف';
        $parent->save();
        $parent->tags()->create(['name' => 'ضغط']);

        $child = ProductCategory::factory()->create(['parent_id' => $parent->id]);
        $child->translateOrNew('ar')->name = 'أكواع';
        $child->save();
        $child->tags()->create(['name' => 'كوع']);

        $headers = ['Accept-Language' => 'ar'];

        $this->getJson('/api/v1/products/categories', $headers)
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.0.name', 'صرف')
            ->assertJsonPath('data.0.tags.0', 'ضغط')
            ->assertJsonPath('data.0.children.0.name', 'أكواع')
            ->assertJsonPath('data.0.children.0.tags.0', 'كوع');

        $this->getJson('/api/v1/products/categories?search=أكواع', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $parent->id)
            ->assertJsonPath('data.0.children.0.id', $child->id)
            ->assertJsonCount(1, 'data.0.children');

        $this->getJson('/api/v1/products/categories?parent_id='.$parent->id.'&tag=كوع', $headers)
            ->assertOk()
            ->assertJsonPath('data.parent.id', $parent->id)
            ->assertJsonPath('data.children.0.id', $child->id)
            ->assertJsonCount(1, 'data.children');
    }

    public function test_products_can_be_filtered_by_category_parent_tag_and_search(): void
    {
        $parent = ProductCategory::factory()->create();
        $child = ProductCategory::factory()->create(['parent_id' => $parent->id]);

        $elbow = Product::factory()->create([
            'product_category_id' => $child->id,
            'classification' => 'premium',
            'points_per_unit' => 1,
        ]);
        $elbow->translateOrNew('ar')->name = 'كوع اختبار';
        $elbow->save();
        ProductTag::syncNamesOntoProduct($elbow, ['كوع']);

        $pipe = Product::factory()->create([
            'product_category_id' => $parent->id,
            'classification' => 'standard',
            'points_per_unit' => 2,
        ]);
        $pipe->translateOrNew('ar')->name = 'ماسورة اختبار';
        $pipe->save();
        ProductTag::syncNamesOntoProduct($pipe, ['ضغط']);

        $headers = ['Accept-Language' => 'ar'];

        $this->getJson('/api/v1/products?parent_id='.$parent->id, $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/products?category_id='.$child->id.'&tag=كوع', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $elbow->id)
            ->assertJsonPath('data.0.tags.0', 'كوع');

        $this->getJson('/api/v1/products?search=ماسورة&classification=standard', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $pipe->id);

        $this->getJson('/api/v1/products/category/'.$parent->id.'?with_children=1&tag=كوع', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $elbow->id)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/products/'.$elbow->id, $headers)
            ->assertOk()
            ->assertJsonPath('data.tags.0', 'كوع');
    }
}

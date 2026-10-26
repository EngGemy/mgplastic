<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductCollectionResource\Pages\CreateProductCollection;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCollectionCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'email' => 'admin-pc@example.com',
            'phone' => '0911111111',
        ]);
    }

    public function test_create_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateProductCollection::class)
            ->assertSuccessful();
    }

    public function test_can_create_collection_with_arabic_title(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateProductCollection::class)
            ->fillForm([
                'title' => 'تشكيلة الأنابيب الأوروبية الخاصة بالموسم',
                'subtitle' => 'عرض تجريبي',
                'kind' => 'featured',
                'is_active' => true,
                'sort_order' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('product_collections', [
            'title' => 'تشكيلة الأنابيب الأوروبية الخاصة بالموسم',
            'kind' => 'featured',
        ]);

        $row = ProductCollection::query()->first();
        $this->assertNotNull($row);
        $this->assertNotEmpty($row->slug);
        $this->assertLessThanOrEqual(80, strlen($row->slug));
    }

    public function test_can_create_with_products_attached(): void
    {
        $this->actingAs($this->admin());

        $product = Product::factory()->create();

        Livewire::test(CreateProductCollection::class)
            ->fillForm([
                'title' => 'مجموعة مع منتجات',
                'kind' => 'custom',
                'is_active' => true,
                'sort_order' => 0,
                'products' => [$product->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $collection = ProductCollection::query()->where('title', 'مجموعة مع منتجات')->first();
        $this->assertNotNull($collection);
        $this->assertTrue($collection->products()->whereKey($product->id)->exists());
    }

    public function test_duplicate_titles_get_unique_slugs(): void
    {
        $this->actingAs($this->admin());

        ProductCollection::query()->create([
            'title' => 'نفس الاسم',
            'slug' => 'nfs-alasm',
            'kind' => 'custom',
        ]);

        Livewire::test(CreateProductCollection::class)
            ->fillForm([
                'title' => 'نفس الاسم',
                'kind' => 'custom',
                'slug' => 'nfs-alasm',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, ProductCollection::query()->where('title', 'نفس الاسم')->count());
        $slugs = ProductCollection::query()->pluck('slug')->all();
        $this->assertCount(2, array_unique($slugs));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductTag;
use App\Models\User;
use App\Support\ProductSizeSorter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tag accordion order must follow the number inside the tag
 * (Excel sheet style: 1 بوصه → 1.5 → 2 → 3 → 4 → 6 → 75مم → 90مم → 110مم → 160مم).
 */
class ProductTagGroupsSortApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    protected function sheetStyleTags(): array
    {
        return [
            '110 مم',
            '6 بوصه',
            '1.5 بوصه',
            '2 بوصه',
            '4 بوصه',
            '160 مم',
            '75 مم',
            '3 بوصه',
            '1 بوصه',
            '90 مم',
        ];
    }

    public function test_unit_sorter_matches_excel_tag_spellings_asc_and_desc(): void
    {
        $groups = array_map(fn ($t) => ['tag' => $t, 'name' => $t], $this->sheetStyleTags());

        $asc = array_column(ProductSizeSorter::sortTagGroupsByNumber($groups, 'asc'), 'tag');
        $this->assertSame(
            ['1 بوصه', '1.5 بوصه', '2 بوصه', '3 بوصه', '4 بوصه', '6 بوصه', '75 مم', '90 مم', '110 مم', '160 مم'],
            $asc,
        );

        $desc = array_column(ProductSizeSorter::sortTagGroupsByNumber($groups, 'desc'), 'tag');
        $this->assertSame(
            ['160 مم', '110 مم', '90 مم', '75 مم', '6 بوصه', '4 بوصه', '3 بوصه', '2 بوصه', '1.5 بوصه', '1 بوصه'],
            $desc,
        );

        // Spelling variant بوصة must parse the same
        $this->assertSame(1.5, ProductSizeSorter::parseDetailed('1.5 بوصة')['value']);
        $this->assertSame(2.0, ProductSizeSorter::parseDetailed('2 بوصة')['value']);
    }

    public function test_admin_products_api_groups_sorted_by_tag_number(): void
    {
        $parent = ProductCategory::factory()->create();
        $parent->translateOrNew('ar')->name = 'قسم الملحقات';
        $parent->save();
        $cat = ProductCategory::factory()->create(['parent_id' => $parent->id]);
        $cat->translateOrNew('ar')->name = 'حسب النظام الامريكي';
        $cat->save();

        foreach ($this->sheetStyleTags() as $i => $tag) {
            $p = Product::factory()->create([
                'product_category_id' => $cat->id,
                'points_per_unit' => 1 + $i,
            ]);
            $p->translateOrNew('ar')->name = 'منتج '.$tag;
            $p->save();
            ProductTag::syncNamesOntoProduct($p, [$tag]);
        }

        $headers = ['Accept-Language' => 'ar'];

        $asc = $this->getJson('/api/v1/products?category_id='.$cat->id.'&size_order=asc&grouped=1', $headers)
            ->assertOk()
            ->assertJsonPath('filters.size_order', 'asc');

        $ascTags = collect($asc->json('groups'))->pluck('tag')->all();
        $this->assertSame(
            ['1 بوصه', '1.5 بوصه', '2 بوصه', '3 بوصه', '4 بوصه', '6 بوصه', '75 مم', '90 مم', '110 مم', '160 مم'],
            $ascTags,
        );
        $this->assertSame(0, $asc->json('groups.0.sort_index'));
        $this->assertSame(9, $asc->json('groups.9.sort_index'));

        // Must NOT be lexicographic (which would put 110 before 2)
        $this->assertNotSame('110 مم', $ascTags[2] ?? null);

        $desc = $this->getJson('/api/v1/products?category_id='.$cat->id.'&size_order=desc', $headers)
            ->assertOk();
        $descTags = collect($desc->json('groups'))->pluck('tag')->all();
        $this->assertSame(
            ['160 مم', '110 مم', '90 مم', '75 مم', '6 بوصه', '4 بوصه', '3 بوصه', '2 بوصه', '1.5 بوصه', '1 بوصه'],
            $descTags,
        );
    }

    public function test_mobile_trader_catalog_groups_sorted_and_group_keys_unique(): void
    {
        $trader = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'is_phone_verified' => true,
        ]);
        Sanctum::actingAs($trader);

        $parent = ProductCategory::factory()->create();
        $parent->translateOrNew('ar')->name = 'قسم الملحقات';
        $parent->save();
        $cat = ProductCategory::factory()->create(['parent_id' => $parent->id]);
        $cat->translateOrNew('ar')->name = 'نظام امريكي';
        $cat->save();

        foreach (['2 بوصه', '1 بوصه', '110 مم', '1.5 بوصه'] as $tag) {
            $p = Product::factory()->create([
                'product_category_id' => $cat->id,
                'points_per_unit' => 1,
            ]);
            $p->translateOrNew('ar')->name = 'عنصر '.$tag;
            $p->save();
            ProductTag::syncNamesOntoProduct($p, [$tag]);
        }

        $res = $this->getJson('/api/v1/mobile/trader/products?category_id='.$cat->id.'&grouped=1&size_order=asc')
            ->assertOk();

        $groups = $res->json('groups') ?? $res->json('data.groups') ?? [];
        $tags = collect($groups)->pluck('tag')->all();
        $keys = collect($groups)->pluck('key')->all();

        $this->assertSame(['1 بوصه', '1.5 بوصه', '2 بوصه', '110 مم'], $tags);
        $this->assertCount(4, array_unique($keys), 'Arabic tag group keys must stay unique (no slug collapse)');
        $this->assertContains('tag:1 بوصه', $keys);
    }

    public function test_paginated_catalog_still_returns_full_sorted_groups(): void
    {
        $trader = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
            'is_phone_verified' => true,
        ]);
        Sanctum::actingAs($trader);

        $cat = ProductCategory::factory()->create();
        $cat->translateOrNew('ar')->name = 'مواسير';
        $cat->save();

        foreach (['6 بوصه', '1 بوصه', '3 بوصه', '2 بوصه'] as $tag) {
            $p = Product::factory()->create([
                'product_category_id' => $cat->id,
                'points_per_unit' => 1,
                'pieces_per_box' => null,
            ]);
            $p->translateOrNew('ar')->name = 'ماسورة '.$tag;
            $p->save();
            ProductTag::syncNamesOntoProduct($p, [$tag]);
        }

        // Force paginated path (pipes family) — groups must still cover ALL tags, not only the page.
        $res = $this->getJson(
            '/api/v1/mobile/trader/products?category_id='.$cat->id.'&product_family=pipe&per_page=1&size_order=asc'
        )->assertOk();

        $json = $res->json();
        $groups = $json['groups'] ?? $json['data']['groups'] ?? [];
        $items = $json['items'] ?? $json['data']['items'] ?? [];

        $this->assertSame('paginated', $json['data']['view'] ?? $json['view'] ?? null, json_encode($json));
        $this->assertCount(1, $items);
        $this->assertCount(4, $groups, 'expected full groups, got: '.json_encode(collect($groups)->pluck('tag')));
        $this->assertSame(
            ['1 بوصه', '2 بوصه', '3 بوصه', '6 بوصه'],
            collect($groups)->pluck('tag')->all(),
        );
    }
}

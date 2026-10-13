<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\WebsiteSetting;
use App\Services\PriceListProductQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PriceListDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_prices_stay_off_the_product_api_and_print_on_the_list(): void
    {
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create([
            'product_category_id' => $category->id,
            'pieces_per_box' => 12,
            'piece_price_lyd' => 9,
            'piece_price_aed' => 8.64,
            'box_price_lyd' => 115,
            'box_price_aed' => 200,
        ]);
        $product->translateOrNew('ar')->name = 'كوع سنترا';
        $product->save();

        $this->getJson('/api/v1/products?category_id='.$category->id)
            ->assertOk()
            ->assertJsonMissingPath('data.0.piece_price_lyd')
            ->assertJsonMissingPath('data.0.box_price_aed');

        $list = PriceList::query()->create([
            'title' => 'قائمة أسعار الملحقات (السوبر)',
            'subtitle' => 'حسب النظام الأوروبي',
            'customer_company' => 'شركة الأمل',
            'issued_on' => '2026-10-13',
            'status' => 'final',
        ]);
        $list->items()->create($product->quoteLinePayload() + ['sort' => 1]);

        $product->update(['piece_price_lyd' => 99]);

        $url = URL::signedRoute('price-lists.share', $list);
        $this->get($url)
            ->assertOk()
            ->assertSee('قائمة أسعار الملحقات (السوبر)')
            ->assertSee('حسب النظام الأوروبي')
            ->assertSee('شركة الأمل')
            ->assertSee('كوع سنترا')
            ->assertSee('12-قطعة')
            ->assertSee('0916126065')
            ->assertDontSee('>99<');
    }

    public function test_footer_phones_come_from_settings_and_filters_skip_existing_products(): void
    {
        WebsiteSetting::instance()->update([
            'price_list_phones' => ['0920000000'],
        ]);

        $category = ProductCategory::factory()->create();
        $kept = Product::factory()->create([
            'product_category_id' => $category->id,
            'piece_price_lyd' => 12,
        ]);
        $kept->translateOrNew('ar')->name = 'مثلث';
        $kept->save();

        $matches = app(PriceListProductQuery::class)->matching($category->id, null, null, 'مثلث', [$kept->id]);
        $this->assertCount(0, $matches);

        $list = PriceList::query()->create([
            'title' => 'عرض',
            'issued_on' => now()->toDateString(),
        ]);
        $list->items()->create($kept->quoteLinePayload());

        $this->get(URL::signedRoute('price-lists.share', $list))
            ->assertOk()
            ->assertSee('0920000000')
            ->assertDontSee('0916126065');

        $this->assertSame('شركة الأمل', PriceList::withCustomerSnapshot([
            'factory_customer_id' => \App\Models\FactoryCustomer::query()->create([
                'company_name' => 'شركة الأمل',
                'contact_name' => 'أحمد',
                'phone' => '0910000000',
            ])->id,
        ])['customer_company']);
    }
}

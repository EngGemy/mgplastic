<?php

namespace Tests\Feature;

use App\Filament\Resources\PriceListResource\Pages\CreatePriceList;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Services\PriceListProductQuery;
use App\Support\PriceListWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
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
            ->assertSee('#1848a0')
            ->assertSee('#18a850')
            ->assertDontSee('#111827')
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

    public function test_new_quote_loads_every_product_for_review_and_saves_edits(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));

        $parent = ProductCategory::factory()->create();
        $parent->translateOrNew('ar')->name = 'ملحقات';
        $parent->save();

        $category = ProductCategory::factory()->create(['parent_id' => $parent->id]);
        $category->translateOrNew('ar')->name = 'أكواع';
        $category->save();

        $product = Product::factory()->create([
            'product_category_id' => $category->id,
            'pieces_per_box' => 12,
            'piece_price_lyd' => 9,
        ]);
        $product->translateOrNew('ar')->name = 'كوع ظاهر';
        $product->save();

        $hidden = Product::factory()->create(['product_category_id' => $category->id]);
        $hidden->translateOrNew('ar')->name = 'مخفي';
        $hidden->save();

        $component = Livewire::test(CreatePriceList::class)
            ->assertSee('مراجعة العرض')
            ->assertSee('كوع ظاهر')
            ->assertSee('مخفي')
            ->assertSee('حفظ وإرسال واتساب');

        $lines = collect($component->get('quoteLines'));
        $keep = $lines->search(fn (array $line) => $line['item_name'] === 'كوع ظاهر');
        $drop = $lines->search(fn (array $line) => $line['item_name'] === 'مخفي');

        $component
            ->fillForm([
                'title' => 'عرض كامل',
                'issued_on' => '2026-10-13',
                'status' => 'draft',
            ])
            ->set('quoteLines.'.$keep.'.piece_price_lyd', '15')
            ->set('quoteLines.'.$drop.'.included', false)
            ->call('create')
            ->assertHasNoFormErrors();

        $list = PriceList::query()->first();
        $this->assertNotNull($list);
        $this->assertCount(1, $list->items);
        $this->assertSame('كوع ظاهر', $list->items->first()->item_name);
        $this->assertEquals(15, (float) $list->items->first()->piece_price_lyd);
    }

    public function test_whatsapp_link_uses_the_customer_phone_on_the_admin_sheet(): void
    {
        $this->assertSame('218916126065', PriceListWhatsApp::international('0916126065'));
        $this->assertNull(PriceListWhatsApp::url('123', 'عرض'));

        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $list = PriceList::query()->create([
            'title' => 'عرض واتساب',
            'customer_company' => 'شركة الأمل',
            'customer_phone' => '0916126065',
            'issued_on' => now()->toDateString(),
            'status' => 'final',
        ]);

        $this->get(route('admin.price-lists.print', $list))
            ->assertOk()
            ->assertSee('https://wa.me/218916126065', false)
            ->assertSee('إرسال واتساب');

        $this->get(URL::signedRoute('price-lists.share', $list))
            ->assertOk()
            ->assertDontSee('https://wa.me/', false);
    }
}

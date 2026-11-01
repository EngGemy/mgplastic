<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Ai\GeminiClient;
use App\Services\InvoiceOcrCartMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceOcrPosCartTest extends TestCase
{
    use RefreshDatabase;

    public function test_matcher_maps_exact_arabic_product_name_into_cart_line(): void
    {
        $product = Product::factory()->create(['points_per_unit' => 1.5, 'pieces_per_box' => 0]);
        $product->translateOrNew('ar')->name = 'ماسورة 4 بوصة';
        $product->save();

        $mapped = app(InvoiceOcrCartMatcher::class)->draftToCart([
            'lines' => [
                ['name' => 'ماسورة 4 بوصة', 'qty' => 3, 'unit_price' => 10, 'line_total' => 30, 'confidence' => 0.9],
            ],
        ]);

        $this->assertCount(1, $mapped['lines']);
        $this->assertSame($product->id, $mapped['lines'][0]['product_id']);
        $this->assertSame(3, $mapped['lines'][0]['quantity']);
        $this->assertSame([], $mapped['unmatched']);
    }

    public function test_matcher_reports_unmatched_lines(): void
    {
        Product::factory()->create();

        $mapped = app(InvoiceOcrCartMatcher::class)->draftToCart([
            'lines' => [
                ['name' => 'صنف غير موجود نهائيا', 'qty' => 2, 'confidence' => 0.4],
            ],
        ]);

        $this->assertSame([], $mapped['lines']);
        $this->assertCount(1, $mapped['unmatched']);
        $this->assertNotEmpty($mapped['errors']);
    }

    public function test_pos_page_ocr_upload_fills_cart(): void
    {
        config([
            'services.gemini.key' => 'fake-key',
            'services.gemini.model' => 'gemini-2.0-flash',
            'services.gemini.ocr_timeout' => 30,
            'services.gemini.daily_limit' => 300,
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create(['points_per_unit' => 2, 'pieces_per_box' => 0]);
        $product->translateOrNew('ar')->name = 'كوع بلاستيك';
        $product->save();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'vendor' => 'المصنع',
                                'currency' => 'LYD',
                                'total' => 20,
                                'lines' => [
                                    ['name' => 'كوع بلاستيك', 'qty' => 4, 'unit_price' => 5, 'line_total' => 20, 'confidence' => 0.95],
                                ],
                                'overall_confidence' => 0.9,
                                'source_type' => 'printed',
                            ], JSON_UNESCAPED_UNICODE),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->assertTrue(app(GeminiClient::class)->enabled());

        $file = UploadedFile::fake()->image('invoice.jpg', 400, 600);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\InvoiceResource\Pages\CreateWholesaleInvoice::class)
            ->set('invoiceOcrImage', $file)
            ->assertSet('cart.'.$product->id.'.quantity', 4)
            ->assertSet('cart.'.$product->id.'.product_id', $product->id);
    }
}

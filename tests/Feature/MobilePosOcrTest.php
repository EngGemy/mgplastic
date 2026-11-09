<?php

namespace Tests\Feature;

use App\Models\OcrImageLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobilePosOcrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config([
            'services.gemini.key' => 'fake-key',
            'services.gemini.model' => 'gemini-2.0-flash',
            'services.gemini.ocr_timeout' => 30,
            'services.gemini.daily_limit' => 300,
            'services.gemini.ocr_thinking_budget' => null,
            'queue.default' => 'sync',
            'ocr_uploads.disk' => 'local',
            'ocr_resilience.primary_model' => 'gemini-2.0-flash',
            'ocr_resilience.fallback_model' => null,
            'ocr_resilience.retry_delays' => [0, 0, 0],
        ]);
    }

    protected function distributor(): User
    {
        return User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
    }

    protected function seedElbowProduct(): Product
    {
        $product = Product::factory()->create([
            'points_per_unit' => 2,
            'pieces_per_box' => 0,
            'catalog_family' => 'elbow_90',
            'angle' => 90,
            'size_value' => 1,
            'size_unit' => 'in',
            'system' => 'us',
            'derived_at' => now(),
        ]);
        $product->translateOrNew('ar')->name = 'كوع 90 – 1 بوصة MG';
        $product->save();

        return $product;
    }

    protected function fakeGemini(array $lines): void
    {
        Http::fake(function ($request) use ($lines) {
            $url = $request->url();
            if (str_contains($url, '/models') && ! str_contains($url, 'generateContent')) {
                return Http::response(['models' => [
                    ['name' => 'models/gemini-2.0-flash', 'supportedGenerationMethods' => ['generateContent']],
                ]]);
            }

            return Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'source_type' => 'handwritten',
                                'lines' => $lines,
                            ], JSON_UNESCAPED_UNICODE),
                        ]],
                    ],
                ]],
                'usageMetadata' => ['totalTokenCount' => 40],
            ], 200);
        });
    }

    public function test_distributor_ocr_upload_review_approve_apply(): void
    {
        $this->assertTrue(app(GeminiClient::class)->enabled());
        $user = $this->distributor();
        $product = $this->seedElbowProduct();
        $this->fakeGemini([
            ['raw_text' => 'كوع ٩٠ ١ بوصه نظام امريك MG', 'name' => 'كوع 90 1 بوصه MG', 'qty' => 4, 'confidence' => 0.95],
        ]);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches', [
            'system_default' => 'us',
        ]);
        $create->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.batch.step', 'draft');

        $batchId = (int) $create->json('data.batch.batch_id');
        $this->assertGreaterThan(0, $batchId);

        $upload = $this->post('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/images', [
            'image' => UploadedFile::fake()->image('note.jpg', 800, 600),
        ], ['Accept' => 'application/json']);

        $upload->assertCreated()
            ->assertJsonPath('status', true);

        $show = $this->getJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId);
        $show->assertOk()
            ->assertJsonPath('data.batch.settled', true);

        $lines = $show->json('data.batch.draft.lines') ?? [];
        $this->assertNotEmpty($lines);
        $lineId = (int) ($lines[0]['ocr_line_id'] ?? 0);
        $this->assertGreaterThan(0, $lineId);

        // Ensure product match (matcher may need approve with product_id)
        $matchedId = (int) ($lines[0]['matched_product_id'] ?? 0);
        if ($matchedId < 1) {
            $this->patchJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/lines/'.$lineId, [
                'action' => 'select_product',
                'product_id' => $product->id,
            ])->assertOk();
        }

        $this->patchJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/lines/'.$lineId, [
            'action' => 'approve',
            'product_id' => $matchedId > 0 ? $matchedId : $product->id,
        ])->assertOk()
            ->assertJsonPath('data.line.approved', true);

        $apply = $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/apply');
        $apply->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.approved_count', 1)
            ->assertJsonPath('message_code', 'ocr_apply_ok');

        $cartLines = $apply->json('data.cart_lines') ?? [];
        $unavailable = $apply->json('data.unavailable_lines') ?? [];
        $this->assertSame(1, count($cartLines) + count($unavailable));
        $recovered = $cartLines[0] ?? $unavailable[0];
        $this->assertSame($product->id, (int) $recovered['product_id']);
        $this->assertSame(4, (int) $recovered['quantity']);
        $this->assertArrayHasKey('available_qty', $recovered);
        $this->assertArrayHasKey('qty_unit', $recovered);

        // Idempotent — second apply blocked but returns recoverable lines
        $again = $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/apply');
        $again->assertStatus(409)
            ->assertJsonPath('error_code', 'OCR_ALREADY_APPLIED')
            ->assertJsonPath('message_code', 'OCR_ALREADY_APPLIED');

        // GET recovers partition after apply
        $showAfter = $this->getJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId);
        $showAfter->assertOk()->assertJsonPath('data.batch.step', 'added');
        $all = array_merge(
            $showAfter->json('data.batch.cart_lines') ?? [],
            $showAfter->json('data.batch.unavailable_lines') ?? [],
        );
        $this->assertSame($product->id, (int) $all[0]['product_id']);

        $this->assertDatabaseHas('ocr_image_lines', [
            'id' => $lineId,
            'review_status' => 'added',
        ]);
    }

    public function test_current_returns_gemini_enabled_when_batch_null(): void
    {
        $user = $this->distributor();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/mobile/distributor/pos/ocr/current')
            ->assertOk()
            ->assertJsonPath('data.resumable', false)
            ->assertJsonPath('data.batch', null)
            ->assertJsonPath('data.gemini_enabled', true);
    }

    public function test_current_returns_resumable_batch(): void
    {
        $user = $this->distributor();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/mobile/distributor/pos/ocr/current')
            ->assertOk()
            ->assertJsonPath('data.resumable', false)
            ->assertJsonPath('data.batch', null);

        $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches')->assertCreated();

        $this->getJson('/api/v1/mobile/distributor/pos/ocr/current')
            ->assertOk()
            ->assertJsonPath('data.resumable', true)
            ->assertJsonPath('data.batch.step', 'draft');
    }

    public function test_trader_can_access_same_ocr_routes(): void
    {
        $trader = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        Sanctum::actingAs($trader);

        $this->postJson('/api/v1/mobile/trader/pos/ocr/batches')
            ->assertCreated()
            ->assertJsonPath('status', true);
    }

    public function test_cannot_access_another_users_batch(): void
    {
        $owner = $this->distributor();
        $other = $this->distributor();

        Sanctum::actingAs($owner);
        $batchId = (int) $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches')
            ->json('data.batch.batch_id');

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId)
            ->assertNotFound();
    }

    public function test_approve_ready_marks_auto_matched_lines(): void
    {
        $user = $this->distributor();
        Sanctum::actingAs($user);
        $product = $this->seedElbowProduct();

        $batchId = (int) $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches')
            ->json('data.batch.batch_id');

        // Seed a ready auto line without going through Gemini
        Storage::disk('local')->put('ocr-uploads/test.jpg', 'x');
        $batch = \App\Models\OcrBatch::findOrFail($batchId);
        $image = \App\Models\OcrImage::query()->create([
            'batch_id' => $batch->id,
            'position' => 1,
            'path' => 'ocr-uploads/test.jpg',
            'sha256' => hash('sha256', 'x'),
            'status' => 'done',
            'read_version' => 1,
            'lines_count' => 1,
        ]);
        $line = OcrImageLine::query()->create([
            'image_id' => $image->id,
            'line_no' => 1,
            'raw_fragment' => 'كوع 90 MG',
            'resolved_product_id' => $product->id,
            'qty' => 2,
            'review_status' => 'ready',
            'version' => 1,
            'payload' => [
                'raw_text' => 'كوع 90 MG',
                'qty' => 2,
                'matched_product_id' => $product->id,
                'match_decision' => 'auto',
                'needs_review' => [],
            ],
        ]);

        $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/approve-ready')
            ->assertOk()
            ->assertJsonPath('data.approved_count', 1);

        $this->assertDatabaseHas('ocr_image_lines', [
            'id' => $line->id,
            'review_status' => 'approved',
        ]);

        $show = $this->getJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId);
        $show->assertOk();
        $draftLine = $show->json('data.batch.draft.lines.0');
        $this->assertArrayHasKey('available_qty', $draftLine);
        $this->assertArrayHasKey('in_stock', $draftLine);
        $this->assertArrayHasKey('qty_exceeds_stock', $draftLine);
        $this->assertArrayHasKey('qty_unit', $draftLine);
        $this->assertArrayHasKey('ui_action', $draftLine);
        $this->assertNotEmpty($show->json('data.batch.lexicon_families'));
        $this->assertSame('seller_pos', $show->json('data.batch.stock_source'));
        $this->assertNotNull($show->json('data.batch.images.0.preview_url'));
        $this->assertArrayNotHasKey('preview_signed_url', $show->json('data.batch.images.0'));
    }

    public function test_duplicate_image_returns_distinct_error_code(): void
    {
        config(['queue.default' => 'sync']);
        $user = $this->distributor();
        Sanctum::actingAs($user);
        $this->fakeGemini([
            ['raw_text' => 'كوع MG', 'name' => 'كوع MG', 'qty' => 1, 'confidence' => 0.9],
        ]);

        $batchId = (int) $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches')
            ->json('data.batch.batch_id');

        $file = UploadedFile::fake()->image('same.jpg', 400, 400);
        $this->post('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/images', [
            'image' => $file,
        ], ['Accept' => 'application/json'])->assertCreated();

        // Same binary content → duplicate sha
        $dup = UploadedFile::fake()->image('same.jpg', 400, 400);
        $this->post('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/images', [
            'image' => $dup,
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'OCR_DUPLICATE_IMAGE');
    }

    public function test_signed_preview_route_serves_image(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ocr-uploads/preview-test.jpg', 'fake-jpeg-bytes');
        $user = $this->distributor();
        $batch = \App\Models\OcrBatch::query()->create([
            'user_id' => $user->id,
            'status' => 'draft',
        ]);
        $image = \App\Models\OcrImage::query()->create([
            'batch_id' => $batch->id,
            'position' => 1,
            'path' => 'ocr-uploads/preview-test.jpg',
            'sha256' => hash('sha256', 'preview'),
            'status' => 'done',
        ]);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'ocr.images.preview',
            now()->addMinutes(5),
            ['image' => $image->id],
        );

        $this->get($url)->assertOk();
    }

    public function test_set_unit_clears_unit_assumed(): void
    {
        $user = $this->distributor();
        Sanctum::actingAs($user);
        $product = $this->seedElbowProduct();
        $product->pieces_per_box = 10;
        $product->save();

        $batchId = (int) $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches')
            ->json('data.batch.batch_id');
        $batch = \App\Models\OcrBatch::findOrFail($batchId);
        $image = \App\Models\OcrImage::query()->create([
            'batch_id' => $batch->id,
            'position' => 1,
            'path' => 'ocr-uploads/u.jpg',
            'sha256' => hash('sha256', 'u'),
            'status' => 'done',
            'read_version' => 1,
            'lines_count' => 1,
        ]);
        $line = OcrImageLine::query()->create([
            'image_id' => $image->id,
            'line_no' => 1,
            'raw_fragment' => 'كوع 90 MG',
            'resolved_product_id' => $product->id,
            'qty' => 2,
            'review_status' => 'ready',
            'version' => 1,
            'payload' => [
                'raw_text' => 'كوع 90 MG',
                'qty' => 2,
                'matched_product_id' => $product->id,
                'pieces_per_box' => 10,
                'match_decision' => 'auto',
                'needs_review' => ['unit_assumed'],
                'qty_unit' => 'box',
                'qty_unit_source' => 'default',
            ],
        ]);

        $this->patchJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/lines/'.$line->id, [
            'action' => 'set_unit',
            'qty_unit' => 'piece',
        ])->assertOk()
            ->assertJsonPath('data.line.qty_unit', 'piece')
            ->assertJsonPath('data.line.qty_unit_source', 'paper');

        $flags = $this->getJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId)
            ->json('data.batch.draft.lines.0.needs_review') ?? [];
        $this->assertNotContains('unit_assumed', $flags);
    }

    public function test_prefs_locked_after_apply(): void
    {
        $user = $this->distributor();
        Sanctum::actingAs($user);
        $batch = \App\Models\OcrBatch::query()->create([
            'user_id' => $user->id,
            'status' => 'added',
            'cart_added_at' => now(),
            'idempotency_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->patchJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batch->id.'/prefs', [
            'system_default' => 'us',
            'reenrich' => true,
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'OCR_BATCH_LOCKED');
    }

    public function test_qty_unit_resolver_detects_paper_words(): void
    {
        $resolver = app(\App\Services\Ocr\OcrQtyUnitResolver::class);
        $box = $resolver->apply(['raw_text' => 'ماسورة 4 صندوق MG', 'qty' => 2, 'pieces_per_box' => 10]);
        $this->assertSame('box', $box['qty_unit']);
        $this->assertSame('paper', $box['qty_unit_source']);

        $piece = $resolver->apply(['raw_text' => 'كوع قطعة MG', 'qty' => 3, 'pieces_per_box' => 10]);
        $this->assertSame('piece', $piece['qty_unit']);
        $this->assertSame('paper', $piece['qty_unit_source']);

        $assumed = $resolver->apply(['raw_text' => 'كوع MG', 'qty' => 2, 'pieces_per_box' => 10]);
        $this->assertSame('box', $assumed['qty_unit']);
        $this->assertSame('default', $assumed['qty_unit_source']);
        $this->assertContains('unit_assumed', $assumed['needs_review']);
    }

    public function test_pos_current_does_not_surface_supply_batch(): void
    {
        $user = $this->distributor();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/mobile/distributor/orders/ocr/batches', [
            'purpose' => 'supply_order',
            'source' => 'factory',
        ])->assertCreated()
            ->assertJsonPath('data.batch.purpose', 'supply_order')
            ->assertJsonPath('data.batch.source', 'factory')
            ->assertJsonPath('data.batch.stock_source', 'factory_catalog');

        $this->getJson('/api/v1/mobile/distributor/pos/ocr/current')
            ->assertOk()
            ->assertJsonPath('data.resumable', false)
            ->assertJsonPath('data.batch', null);

        $this->getJson('/api/v1/mobile/distributor/orders/ocr/current?source=factory')
            ->assertOk()
            ->assertJsonPath('data.resumable', true)
            ->assertJsonPath('data.batch.purpose', 'supply_order')
            ->assertJsonPath('data.batch.source', 'factory');
    }

    public function test_warehouse_current_scoped_by_warehouse_id(): void
    {
        $user = $this->distributor();
        $whA = $this->distributor();
        $whB = $this->distributor();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/mobile/distributor/orders/ocr/batches', [
            'source' => 'warehouse',
            'warehouse_id' => $whA->id,
        ])->assertCreated();

        $this->getJson('/api/v1/mobile/distributor/orders/ocr/current?source=warehouse&warehouse_id='.$whB->id)
            ->assertOk()
            ->assertJsonPath('data.resumable', false);

        $this->getJson('/api/v1/mobile/distributor/orders/ocr/current?source=warehouse&warehouse_id='.$whA->id)
            ->assertOk()
            ->assertJsonPath('data.resumable', true)
            ->assertJsonPath('data.batch.supplier_user_id', $whA->id)
            ->assertJsonPath('data.batch.stock_source', 'warehouse_stock');
    }

    public function test_plumber_supply_ocr_keeps_oos_in_cart_lines(): void
    {
        $shop = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_approved' => true,
            'is_active' => true,
        ]);
        Sanctum::actingAs($plumber);
        $product = $this->seedElbowProduct();

        $batchId = (int) $this->postJson('/api/v1/mobile/plumber/orders/ocr/batches', [
            'source' => 'shop',
            'retail_trader_id' => $shop->id,
        ])->assertCreated()
            ->assertJsonPath('data.batch.purpose', 'supply_order')
            ->assertJsonPath('data.batch.source', 'shop')
            ->assertJsonPath('data.batch.stock_source', 'shop_stock')
            ->json('data.batch.batch_id');

        Storage::disk('local')->put('ocr-uploads/plumb.jpg', 'p');
        $batch = \App\Models\OcrBatch::findOrFail($batchId);
        $image = \App\Models\OcrImage::query()->create([
            'batch_id' => $batch->id,
            'position' => 1,
            'path' => 'ocr-uploads/plumb.jpg',
            'sha256' => hash('sha256', 'p'),
            'status' => 'done',
            'read_version' => 1,
            'lines_count' => 1,
        ]);
        $line = OcrImageLine::query()->create([
            'image_id' => $image->id,
            'line_no' => 1,
            'raw_fragment' => 'كوع 90 MG',
            'resolved_product_id' => $product->id,
            'qty' => 2,
            'review_status' => 'ready',
            'version' => 1,
            'payload' => [
                'raw_text' => 'كوع 90 MG',
                'matched_product_id' => $product->id,
                'qty' => 2,
                'match_decision' => 'auto',
                'match_status' => 'matched',
                'review_status' => 'ready',
            ],
        ]);

        $this->patchJson('/api/v1/mobile/plumber/orders/ocr/batches/'.$batchId.'/lines/'.$line->id, [
            'action' => 'approve',
        ])->assertOk();

        $apply = $this->postJson('/api/v1/mobile/plumber/orders/ocr/batches/'.$batchId.'/apply')
            ->assertOk();

        $this->assertNotEmpty($apply->json('data.cart_lines'));
        $this->assertSame([], $apply->json('data.unavailable_lines'));
        $this->assertFalse($apply->json('data.affects_inventory'));
        $this->assertFalse($apply->json('data.affects_points'));
        $this->assertSame('shop_stock', $apply->json('data.stock_source'));
        $this->assertArrayHasKey('place_order', $apply->json('data.next'));
        $this->assertFalse((bool) ($apply->json('data.cart_lines.0.in_stock') ?? true));
    }

    public function test_plumber_cannot_use_pos_ocr(): void
    {
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_approved' => true,
            'is_active' => true,
        ]);
        Sanctum::actingAs($plumber);

        $this->postJson('/api/v1/mobile/plumber/orders/ocr/batches', [
            'purpose' => 'pos',
        ])->assertStatus(422);
    }

    public function test_factory_apply_ignores_stock_and_keeps_lines(): void
    {
        $user = $this->distributor();
        Sanctum::actingAs($user);
        $product = $this->seedElbowProduct();

        $batchId = (int) $this->postJson('/api/v1/mobile/distributor/orders/ocr/batches', [
            'source' => 'factory',
        ])->assertCreated()
            ->assertJsonPath('data.batch.stock_source', 'factory_catalog')
            ->json('data.batch.batch_id');

        Storage::disk('local')->put('ocr-uploads/fac.jpg', 'f');
        $batch = \App\Models\OcrBatch::findOrFail($batchId);
        $image = \App\Models\OcrImage::query()->create([
            'batch_id' => $batch->id,
            'position' => 1,
            'path' => 'ocr-uploads/fac.jpg',
            'sha256' => hash('sha256', 'f'),
            'status' => 'done',
            'read_version' => 1,
            'lines_count' => 1,
        ]);
        $line = OcrImageLine::query()->create([
            'image_id' => $image->id,
            'line_no' => 1,
            'raw_fragment' => 'كوع 90 MG',
            'resolved_product_id' => $product->id,
            'qty' => 5,
            'review_status' => 'ready',
            'version' => 1,
            'payload' => [
                'raw_text' => 'كوع 90 MG',
                'matched_product_id' => $product->id,
                'qty' => 5,
                'match_decision' => 'auto',
                'match_status' => 'matched',
                'review_status' => 'ready',
            ],
        ]);

        $this->patchJson('/api/v1/mobile/distributor/orders/ocr/batches/'.$batchId.'/lines/'.$line->id, [
            'action' => 'approve',
        ])->assertOk();

        $apply = $this->postJson('/api/v1/mobile/distributor/orders/ocr/batches/'.$batchId.'/apply')
            ->assertOk();

        $this->assertCount(1, $apply->json('data.cart_lines'));
        $this->assertSame([], $apply->json('data.unavailable_lines'));
        $this->assertTrue($apply->json('data.cart_lines.0.in_stock'));
        $this->assertNull($apply->json('data.cart_lines.0.available_qty'));
        $this->assertFalse($apply->json('data.affects_inventory'));
    }

    public function test_trader_supply_ocr_from_wholesaler(): void
    {
        $wholesaler = $this->distributor();
        $trader = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        Sanctum::actingAs($trader);

        $this->postJson('/api/v1/mobile/trader/orders/ocr/batches', [
            'source' => 'wholesale',
            'wholesaler_id' => $wholesaler->id,
        ])->assertCreated()
            ->assertJsonPath('data.batch.purpose', 'supply_order')
            ->assertJsonPath('data.batch.source', 'wholesale')
            ->assertJsonPath('data.batch.supplier_user_id', $wholesaler->id)
            ->assertJsonPath('data.batch.stock_source', 'wholesaler_stock');
    }
}

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
        config([
            'services.gemini.key' => 'fake-key',
            'services.gemini.model' => 'gemini-2.0-flash',
            'services.gemini.ocr_timeout' => 30,
            'services.gemini.daily_limit' => 300,
            'services.gemini.ocr_thinking_budget' => null,
            'queue.default' => 'sync',
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
            ->assertJsonPath('data.approved_count', 1);

        $cartLines = $apply->json('data.cart_lines');
        $this->assertIsArray($cartLines);
        $this->assertSame($product->id, (int) $cartLines[0]['product_id']);
        $this->assertSame(4, (int) $cartLines[0]['quantity']);

        // Idempotent — second apply blocked
        $this->postJson('/api/v1/mobile/distributor/pos/ocr/batches/'.$batchId.'/apply')
            ->assertStatus(409);

        $this->assertDatabaseHas('ocr_image_lines', [
            'id' => $lineId,
            'review_status' => 'added',
        ]);
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
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\ProcessOcrImageJob;
use App\Models\OcrBatch;
use App\Models\OcrImage;
use App\Models\OcrImageLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use App\Services\Ocr\OcrAttemptPolicy;
use App\Services\Ocr\OcrBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class OcrBatchResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'services.gemini.key' => 'fake-key',
            'services.gemini.model' => 'gemini-2.0-flash',
            'services.gemini.ocr_timeout' => 5,
            'services.gemini.daily_limit' => 300,
            'services.gemini.ocr_thinking_budget' => null,
            'ocr_resilience.primary_model' => 'gemini-2.0-flash',
            'ocr_resilience.fallback_model' => null,
            'ocr_resilience.retry_delays' => [0, 0, 0],
            'ocr_resilience.retry_budget_seconds' => 90,
            'ocr_resilience.max_parallel_per_batch' => 2,
            'queue.default' => 'sync',
        ]);
        Cache::flush();
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    protected function fakeJpeg(string $name = 'note.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 600);
    }

    protected function geminiOkPayload(array $lines): array
    {
        return [
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
        ];
    }

    public function test_timeout_twice_then_success(): void
    {
        $user = $this->admin();
        $genCalls = 0;
        Http::fake(function ($request) use (&$genCalls) {
            $url = $request->url();
            if (str_contains($url, '/models') && ! str_contains($url, 'generateContent')) {
                return Http::response(['models' => [
                    ['name' => 'models/gemini-2.0-flash', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']],
                ]]);
            }
            $genCalls++;
            if ($genCalls <= 2) {
                return Http::response(['error' => 'gateway'], 504);
            }

            return Http::response($this->geminiOkPayload([
                ['raw_text' => 'ماسورة 4 MG', 'name' => 'ماسورة 4 MG', 'qty' => 2, 'confidence' => 0.9],
            ]));
        });

        $batch = app(OcrBatchService::class)->createOrResume($user);
        $added = app(OcrBatchService::class)->addUploadedImage($batch, $this->fakeJpeg());
        $this->assertTrue($added['ok']);
        $image = $added['image']->fresh();

        $this->assertSame('done', $image->status, 'last_error='.$image->last_error_code.' attempts='.$image->attempts);
        $this->assertGreaterThanOrEqual(1, $image->lines_count);
        $this->assertGreaterThanOrEqual(3, $genCalls);
    }

    public function test_429_honors_retry_after(): void
    {
        $policy = app(OcrAttemptPolicy::class);
        $delay = $policy->delaySeconds(1, 12);
        $this->assertSame(12, $delay);
        $this->assertSame(60, $policy->delaySeconds(1, 120)); // capped
    }

    public function test_404_model_switches_to_fallback(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/models') && ! str_contains($url, 'generateContent')) {
                return Http::response(['models' => [
                    ['name' => 'models/gemini-2.0-flash', 'supportedGenerationMethods' => ['generateContent']],
                    ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']],
                ]]);
            }
            if (str_contains($url, 'gemini-2.0-flash')) {
                return Http::response(['error' => 'missing'], 404);
            }

            return Http::response($this->geminiOkPayload([
                ['raw_text' => 'كوع 1 MG', 'name' => 'كوع 1 MG', 'qty' => 1, 'confidence' => 0.9],
            ]));
        });

        $user = $this->admin();
        $batch = app(OcrBatchService::class)->createOrResume($user);
        $added = app(OcrBatchService::class)->addUploadedImage($batch, $this->fakeJpeg('a.jpg'));
        $image = $added['image']->fresh();

        $this->assertContains($image->status, ['done', 'empty'], $image->last_error_code ?? '');
        $fallback = app(GeminiClient::class)->resolveOcrFallbackModel('gemini-2.0-flash');
        $this->assertSame('gemini-2.5-flash', $fallback);
    }

    public function test_401_no_retry(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'auth'], 401),
        ]);

        $user = $this->admin();
        $batch = app(OcrBatchService::class)->createOrResume($user);
        $added = app(OcrBatchService::class)->addUploadedImage($batch, $this->fakeJpeg());
        $image = $added['image']->fresh();

        $this->assertSame('failed', $image->status);
        $this->assertSame('OCR-AUTH', $image->last_error_code);
        $this->assertSame(1, $image->attempts);
    }

    public function test_invalid_json_retried_once_then_failed(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/models') && ! str_contains($url, 'generateContent')) {
                return Http::response(['models' => []]);
            }

            return Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => 'NOT-JSON{{{']]],
                ]],
            ]);
        });

        $user = $this->admin();
        $batch = app(OcrBatchService::class)->createOrResume($user);
        $added = app(OcrBatchService::class)->addUploadedImage($batch, $this->fakeJpeg());
        $image = $added['image']->fresh();

        $this->assertSame('failed', $image->status);
        $this->assertSame('OCR-EMPTY-JSON', $image->last_error_code);
        $this->assertLessThanOrEqual(2, $image->attempts);
    }

    public function test_three_images_one_fails_others_reach_review(): void
    {
        Queue::fake();
        $user = $this->admin();
        $svc = app(OcrBatchService::class);
        $batch = $svc->createOrResume($user);
        $ids = [];
        // Distinct pixel sizes so sha256 differs (fake()->image same WxH can collide).
        foreach ([[640, 480], [641, 480], [642, 480]] as $i => [$w, $h]) {
            $added = $svc->addUploadedImage($batch, UploadedFile::fake()->image(($i + 1).'.jpg', $w, $h));
            $this->assertTrue($added['ok'], $added['error'] ?? 'add failed');
            $ids[] = $added['image']->id;
        }

        // Mark #2 failed (auth) without blocking siblings — then process #1 and #3 successfully.
        OcrImage::query()->where('id', $ids[1])->update([
            'status' => 'failed',
            'last_error_code' => 'OCR-AUTH',
            'attempts' => 1,
            'finished_at' => now(),
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/models') && ! str_contains($request->url(), 'generateContent')) {
                return Http::response(['models' => []]);
            }

            return Http::response($this->geminiOkPayload([
                ['raw_text' => 'بند ناجح', 'name' => 'بند ناجح', 'qty' => 1, 'confidence' => 0.9],
            ]));
        });

        foreach ([$ids[0], $ids[2]] as $imageId) {
            (new ProcessOcrImageJob($imageId))->handle(
                app(\App\Services\Ocr\InvoiceOcrPipeline::class),
                app(OcrAttemptPolicy::class),
                app(\App\Services\Ocr\OcrCircuitBreaker::class),
                app(OcrBatchService::class),
                app(GeminiClient::class),
            );
        }

        $batch->refresh()->load('images');
        $this->assertSame('failed', $batch->images->firstWhere('id', $ids[1])->status);
        $this->assertSame('OCR-AUTH', $batch->images->firstWhere('id', $ids[1])->last_error_code);
        $this->assertSame(2, $batch->images->whereIn('status', ['done', 'empty'])->count());

        $draft = $svc->buildPosDraft($batch->fresh());
        $this->assertNotNull($draft['draft']);
        $lineTotal = count($draft['draft']['lines'] ?? []) + count($draft['draft']['excluded'] ?? []);
        $this->assertGreaterThan(0, $lineTotal);
        $this->assertTrue($batch->fresh()->images->firstWhere('id', $ids[1])->isSettled());
        $this->assertTrue($batch->fresh()->images->firstWhere('id', $ids[0])->isSettled());
    }

    public function test_replace_keeps_approved_lines(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiOkPayload([
                ['raw_text' => 'قديم', 'name' => 'قديم', 'qty' => 1, 'confidence' => 0.9],
                ['raw_text' => 'جديد', 'name' => 'جديد', 'qty' => 2, 'confidence' => 0.9],
            ])),
        ]);

        $user = $this->admin();
        $svc = app(OcrBatchService::class);
        $batch = $svc->createOrResume($user);
        $old = $svc->addUploadedImage($batch, $this->fakeJpeg('old.jpg'))['image']->fresh();
        $line = $old->currentLines()->first();
        $this->assertNotNull($line);
        $line->review_status = 'approved';
        $line->save();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiOkPayload([
                ['raw_text' => 'بديل فقط', 'name' => 'بديل فقط', 'qty' => 3, 'confidence' => 0.9],
            ])),
        ]);

        $replaced = $svc->replaceImage($old, $this->fakeJpeg('new.jpg'), keepApproved: true);
        $this->assertTrue($replaced['ok']);
        $new = $replaced['image']->fresh();
        $kept = $new->lines()->where('review_status', 'approved')->count();
        $this->assertGreaterThanOrEqual(1, $kept);
    }

    public function test_duplicate_sha256_skipped(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiOkPayload([
                ['raw_text' => 'س', 'name' => 'س', 'qty' => 1, 'confidence' => 0.9],
            ])),
        ]);

        $user = $this->admin();
        $svc = app(OcrBatchService::class);
        $batch = $svc->createOrResume($user);
        $file = $this->fakeJpeg('same.jpg');
        // Same content twice: fake images with same name/size may differ bytes; store once and re-add path.
        $first = $svc->addUploadedImage($batch, $file);
        $this->assertTrue($first['ok']);
        $path = $first['image']->path;
        $second = $svc->addUploadedImage($batch, $path, forceDuplicate: false);
        $this->assertTrue($second['duplicate']);
    }

    public function test_duplicate_product_suggests_merge_not_auto(): void
    {
        $product = Product::factory()->create([
            'points_per_unit' => 1,
            'pieces_per_box' => 0,
            'catalog_family' => 'pipe',
            'size_value' => 4,
            'size_unit' => 'in',
            'system' => 'us',
            'derived_at' => now(),
        ]);
        $product->translateOrNew('ar')->name = 'ماسورة 4 بوصة';
        $product->save();

        $user = $this->admin();
        $batch = OcrBatch::query()->create(['user_id' => $user->id, 'status' => 'review']);
        foreach ([1, 2] as $pos) {
            $img = OcrImage::query()->create([
                'batch_id' => $batch->id,
                'position' => $pos,
                'path' => 'ocr/'.$pos.'.jpg',
                'sha256' => hash('sha256', 'x'.$pos),
                'status' => 'done',
                'read_version' => 1,
                'lines_count' => 1,
            ]);
            OcrImageLine::query()->create([
                'image_id' => $img->id,
                'line_no' => 1,
                'raw_fragment' => 'ماسورة 4',
                'resolved_product_id' => $product->id,
                'qty' => $pos === 1 ? 6 : 4,
                'review_status' => 'ready',
                'version' => 1,
                'payload' => [
                    'matched_product_id' => $product->id,
                    'qty' => $pos === 1 ? 6 : 4,
                    'slots' => ['size_value' => 4],
                    'needs_review' => [],
                ],
            ]);
        }

        $built = app(OcrBatchService::class)->buildPosDraft($batch);
        $this->assertNotEmpty($built['merge_suggestions']);
        $this->assertSame(10.0, (float) $built['merge_suggestions'][0]['sum']);
        $this->assertCount(2, $built['draft']['lines']); // not auto-merged
    }

    public function test_double_click_add_to_cart_idempotent(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiOkPayload([
                ['raw_text' => 'ماسورة 4 بوصة MG', 'name' => 'ماسورة 4 بوصة MG', 'qty' => 2, 'confidence' => 0.95],
            ])),
        ]);

        $product = Product::factory()->create([
            'points_per_unit' => 1,
            'pieces_per_box' => 0,
            'catalog_family' => 'pipe',
            'size_value' => 4,
            'size_unit' => 'in',
            'system' => 'us',
            'derived_at' => now(),
        ]);
        $product->translateOrNew('ar')->name = 'ماسورة 4 بوصة';
        $product->save();

        $admin = $this->admin();
        $component = Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\InvoiceResource\Pages\CreateWholesaleInvoice::class)
            ->set('invoiceOcrImage', $this->fakeJpeg());

        $draft = $component->get('ocrDraft');
        $this->assertIsArray($draft);
        if (! empty($draft['lines'][0])) {
            $component->call('selectOcrCandidate', 0, $product->id);
            $component->call('confirmOcrLine', 0);
        }

        // Approve whatever matched.
        $lines = $component->get('ocrDraft')['lines'] ?? [];
        foreach ($lines as $i => $line) {
            if (! empty($line['matched_product_id'])) {
                $component->call('confirmOcrLine', $i);
            }
        }

        $component->call('applyApprovedOcrToCart');
        $cart1 = $component->get('cart');
        $batchId = $component->get('ocrBatchId');
        $component->call('applyApprovedOcrToCart');
        $cart2 = $component->get('cart');
        $this->assertEquals($cart1, $cart2);
        if ($batchId) {
            $this->assertNotNull(OcrBatch::query()->find($batchId)?->cart_added_at);
        }
    }

    public function test_refresh_resumes_draft_batch(): void
    {
        $user = $this->admin();
        $batch = app(OcrBatchService::class)->createOrResume($user);
        $batch->status = 'review';
        $batch->save();

        Livewire::actingAs($user)
            ->test(\App\Filament\Resources\InvoiceResource\Pages\CreateWholesaleInvoice::class)
            ->assertSet('ocrBatchId', $batch->id)
            ->assertSet('ocrResumeBanner', true);
    }

    public function test_accounting_identity_holds(): void
    {
        $user = $this->admin();
        $batch = OcrBatch::query()->create(['user_id' => $user->id, 'status' => 'review']);
        $img = OcrImage::query()->create([
            'batch_id' => $batch->id,
            'position' => 1,
            'path' => 'ocr/a.jpg',
            'sha256' => hash('sha256', 'a'),
            'status' => 'done',
            'read_version' => 1,
            'lines_count' => 2,
        ]);
        foreach ([
            ['review_status' => 'ready', 'raw' => 'a'],
            ['review_status' => 'needs', 'raw' => 'b'],
            ['review_status' => 'excluded', 'raw' => 'c'],
        ] as $i => $row) {
            OcrImageLine::query()->create([
                'image_id' => $img->id,
                'line_no' => $i + 1,
                'raw_fragment' => $row['raw'],
                'qty' => 1,
                'review_status' => $row['review_status'],
                'version' => 1,
                'payload' => [],
            ]);
        }

        $acct = app(OcrBatchService::class)->accountingIdentity($batch);
        $this->assertTrue($acct['ok']);
        $this->assertSame(3, $acct['batch']['lines_read']);
        $this->assertSame(
            $acct['batch']['matched'] + $acct['batch']['needs_review'] + $acct['batch']['excluded'],
            $acct['batch']['lines_read']
        );
    }
}

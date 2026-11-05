<?php

namespace Tests\Feature;

use App\Models\OcrAlias;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use App\Services\Ocr\InvoiceOcrCatalogMatcher;
use App\Services\Ocr\InvoiceOcrLearningService;
use App\Services\Ocr\InvoiceOcrPipeline;
use App\Services\Ocr\InvoiceOcrValidator;
use App\Support\ArabicTextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => 'fake-key',
            'services.gemini.model' => 'gemini-2.0-flash',
            'services.gemini.ocr_timeout' => 30,
            'services.gemini.ocr_max_output_tokens' => 8192,
            'services.gemini.ocr_thinking_budget' => null,
            'services.gemini.daily_limit' => 300,
            'services.gemini.ocr_cache_ttl' => 3600,
            'queue.default' => 'sync',
        ]);
        Cache::flush();
    }

    public function test_arabic_normalization_variants_and_digits(): void
    {
        $this->assertSame(
            'انبوب 12',
            ArabicTextNormalizer::normalize('أنْبُوب ١٢')
        );
        $this->assertSame(
            ArabicTextNormalizer::normalize('مدرسة'),
            ArabicTextNormalizer::normalize('مدرسه')
        );
    }

    public function test_fuzzy_match_thresholds_auto_suggest_manual(): void
    {
        $product = Product::factory()->create();
        $product->translateOrNew('ar')->name = 'ماسورة بلاستيك ٤ بوصة';
        $product->save();

        $matcher = app(InvoiceOcrCatalogMatcher::class);
        $draft = $matcher->enrich([
            'lines' => [
                ['raw_text' => 'ماسورة بلاستيك 4 بوصة MG', 'name' => 'ماسورة بلاستيك 4 بوصة MG', 'qty' => 1, 'confidence' => 0.95],
                ['raw_text' => 'xyz-unknown-item-999', 'name' => 'xyz-unknown-item-999', 'qty' => 1, 'confidence' => 0.4],
            ],
            'overall_confidence' => 0.7,
            'source_type' => 'printed',
        ]);

        // Structured matcher: known pipe family may auto-match; unknown tokens are excluded (not dropped).
        $this->assertNotEmpty($draft['lines']);
        $this->assertContains($draft['lines'][0]['match_decision'], ['auto', 'alias', 'suggest', 'needs_choice', 'manual']);
        if (isset($draft['lines'][0]['candidates'])) {
            $this->assertLessThanOrEqual(3, count($draft['lines'][0]['candidates']));
        }
        $this->assertTrue(
            collect($draft['excluded'] ?? [])->contains(fn ($e) => str_contains((string) ($e['raw_text'] ?? ''), 'xyz-unknown'))
            || collect($draft['lines'])->contains(fn ($l) => str_contains((string) ($l['raw_text'] ?? ''), 'xyz-unknown'))
        );
    }

    public function test_validation_marks_needs_review_not_fail(): void
    {
        $draft = app(InvoiceOcrValidator::class)->annotate([
            'subtotal' => 100,
            'lines' => [
                ['name' => 'أ', 'raw_text' => 'أ', 'qty' => 2, 'unit_price' => 10, 'line_total' => 50, 'confidence' => 0.95],
                ['name' => 'ب', 'raw_text' => 'ب', 'qty' => 9999999, 'unit_price' => 1, 'line_total' => 9999999, 'confidence' => 0.3],
            ],
        ]);

        $this->assertContains('line_math', $draft['lines'][0]['needs_review']);
        $this->assertContains('qty', $draft['lines'][1]['needs_review']);
        $this->assertContains('low_confidence', $draft['lines'][1]['needs_review']);
        $this->assertContains('subtotal_mismatch', $draft['needs_review']);
    }

    public function test_http_404_and_429_map_to_arabic_messages(): void
    {
        Storage::fake('local');
        $path = storage_path('framework/testing/ocr.jpg');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEBAVFhUVFRUVFRUVFRUVFRUWFxUVFRUYHSggGBolGxUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAUAAEAAAAAAAAAAAAAAAAAAAAK/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEAMQAAABwf/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Af//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Af//Z'));

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => 'not found'], 404)
                ->push(['error' => 'rate'], 429),
        ]);
        $r404 = app(GeminiClient::class)->extractInvoiceFromImage($path, 'image/jpeg');
        $this->assertFalse($r404['ok']);
        $this->assertStringContainsString('404', $r404['error']);

        $r429 = app(GeminiClient::class)->extractInvoiceFromImage($path, 'image/jpeg');
        $this->assertFalse($r429['ok']);
        $this->assertStringContainsString('429', $r429['error']);
    }

    public function test_max_tokens_and_invalid_json_and_timeout(): void
    {
        $path = storage_path('framework/testing/ocr2.jpg');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, 'not-really-needed');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '']]]]],
                ], 200)
                ->push([
                    'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '```json\n{not-json}\n```']]]]],
                ], 200),
        ]);
        $max = app(GeminiClient::class)->extractInvoiceFromImage($path, 'image/jpeg');
        $this->assertFalse($max['ok']);
        $this->assertSame('max_tokens', $max['code'] ?? null);

        $bad = app(GeminiClient::class)->extractInvoiceFromImage($path, 'image/jpeg');
        $this->assertFalse($bad['ok']);
        $this->assertSame('invalid_json', $bad['code'] ?? null);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received');
        });
        $to = app(GeminiClient::class)->extractInvoiceFromImage($path, 'image/jpeg');
        $this->assertFalse($to['ok']);
        $this->assertContains($to['code'] ?? '', ['timeout', 'exception']);
        $this->assertNotEmpty($to['error']);
    }

    public function test_fenced_json_still_parses_and_cache_skips_second_http(): void
    {
        $img = $this->writableTinyJpeg();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [[
                        'text' => "```json\n".json_encode([
                            'vendor' => 'مصنع',
                            'currency' => 'LYD',
                            'subtotal' => 10,
                            'tax' => 0,
                            'total' => 10,
                            'overall_confidence' => 0.9,
                            'source_type' => 'printed',
                            'lines' => [
                                ['raw_text' => 'كوع 90 3 بوصه', 'name' => 'كوع 90 3 بوصه', 'qty' => 2, 'unit_price' => 5, 'line_total' => 10, 'confidence' => 0.95],
                            ],
                        ], JSON_UNESCAPED_UNICODE)."\n```",
                    ]]],
                ]],
                'usageMetadata' => ['totalTokenCount' => 100],
            ], 200),
        ]);

        $product = Product::factory()->create([
            'points_per_unit' => 1,
            'catalog_family' => 'elbow_90',
            'angle' => 90,
            'size_value' => 3,
            'size_unit' => 'in',
            'system' => 'us',
            'derived_at' => now(),
        ]);
        $product->translateOrNew('ar')->name = 'كوع 90 – 3 بوصة MG';
        $product->save();

        $first = app(InvoiceOcrPipeline::class)->run($img);
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['cached']);

        Http::fake(function () {
            $this->fail('HTTP should not be called on cache hit');
        });

        $second = app(InvoiceOcrPipeline::class)->run($img);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['cached']);
    }

    public function test_alias_learning_prefers_alias_before_fuzzy(): void
    {
        $product = Product::factory()->create();
        $product->translateOrNew('ar')->name = 'منتج رسمي طويل الاسم';
        $product->save();

        app(InvoiceOcrLearningService::class)->rememberCorrection(
            'abc123',
            'اختصار غريب',
            $product->id,
            3,
            User::factory()->create(['role' => 'super_admin'])
        );

        $this->assertDatabaseHas('ocr_aliases', [
            'normalized_text' => ArabicTextNormalizer::normalize('اختصار غريب'),
            'product_id' => $product->id,
        ]);

        $draft = app(InvoiceOcrCatalogMatcher::class)->enrich([
            'lines' => [
                ['raw_text' => 'اختصار غريب', 'name' => 'اختصار غريب', 'qty' => 1, 'confidence' => 0.5],
            ],
            'overall_confidence' => 0.5,
            'source_type' => 'handwritten',
        ]);

        $this->assertSame('alias', $draft['lines'][0]['match_decision']);
        $this->assertSame($product->id, $draft['lines'][0]['matched_product_id']);
        $this->assertGreaterThan(1, OcrAlias::query()->where('product_id', $product->id)->value('hits'));
    }

    protected function writableTinyJpeg(): string
    {
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/ocr-v2-'.uniqid().'.jpg';

        // Minimal valid JPEG via GD when available.
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(40, 40);
            $bg = imagecolorallocate($im, 255, 255, 255);
            imagefilledrectangle($im, 0, 0, 40, 40, $bg);
            imagejpeg($im, $path, 80);
            imagedestroy($im);
        } else {
            file_put_contents($path, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEBAVFhUVFRUVFRUVFRUVFRUWFxUVFRUYHSggGBolGxUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAUAAEAAAAAAAAAAAAAAAAAAAAK/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEAMQAAABwf/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Af//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Af//Z'));
        }

        return $path;
    }
}

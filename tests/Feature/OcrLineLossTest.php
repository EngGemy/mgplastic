<?php

namespace Tests\Feature;

use App\Services\Ai\GeminiClient;
use App\Services\Ocr\InvoiceOcrPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcrLineLossTest extends TestCase
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
            'services.gemini.ocr_catalog_prompt_limit' => 0,
            'services.gemini.daily_limit' => 300,
            'services.gemini.ocr_cache_ttl' => 3600,
            'queue.default' => 'sync',
            'logging.default' => 'ocr',
        ]);
        Cache::flush();
    }

    /** @return list<array<string, mixed>> */
    protected function fourHandwrittenLines(): array
    {
        return [
            [
                'raw_text' => '١ وصله عاديه ١ بوصه',
                'name' => '١ وصله عاديه ١ بوصه',
                'qty' => 2,
            ],
            [
                'raw_text' => '٢ كوع ٩٠ ١ بوصه نظاف اتك',
                'name' => '٢ كوع ٩٠ ١ بوصه نظاف اتك',
                'qty' => 3,
                'unit_price' => null,
                'line_total' => null,
                'confidence' => null,
            ],
            [
                'raw_text' => '٣ وصله سن خارجي ١ بوصه نظاف ارم',
                'name' => '٣ وصله سن خارجي ١ بوصه نظاف ارم',
                'qty' => 5,
            ],
            [
                'raw_text' => '٤ نهية نظافه ١,٥ بوصه',
                'name' => '٤ نهية نظافه ١,٥ بوصه',
                'qty' => 7,
                'confidence' => 0.4,
            ],
        ];
    }

    protected function fakeGeminiDraft(array $lines, string $finishReason = 'STOP', int $times = 5): void
    {
        $payload = [
            'candidates' => [[
                'finishReason' => $finishReason,
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'vendor' => null,
                            'currency' => 'LYD',
                            'overall_confidence' => 0.7,
                            'source_type' => 'handwritten',
                            'lines' => $lines,
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ],
            ]],
            'usageMetadata' => ['candidatesTokenCount' => 140, 'thoughtsTokenCount' => 100],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($payload, 200),
        ]);
    }

    protected function tinyJpegPath(): string
    {
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/ocr-line-loss-'.uniqid().'.jpg';
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(80, 80);
            $bg = imagecolorallocate($im, 255, 255, 255);
            imagefilledrectangle($im, 0, 0, 80, 80, $bg);
            imagejpeg($im, $path, 80);
            imagedestroy($im);
        } else {
            file_put_contents($path, 'x');
        }

        return $path;
    }

    public function test_four_quantity_only_lines_survive_extract_and_pipeline(): void
    {
        $this->fakeGeminiDraft($this->fourHandwrittenLines());
        $path = $this->tinyJpegPath();

        $extract = app(GeminiClient::class)->extractInvoiceFromImage($path, 'image/jpeg');
        $this->assertTrue($extract['ok'], $extract['error'] ?? 'extract failed');
        $this->assertCount(4, $extract['draft']['lines'] ?? []);
        $this->assertSame([2.0, 3.0, 5.0, 7.0], array_map(
            fn ($l) => (float) $l['qty'],
            $extract['draft']['lines']
        ));
        $this->assertSame(1, $extract['draft']['lines'][0]['line_no']);
        $this->assertStringStartsNotWith('1', $extract['draft']['lines'][0]['name']);
        $this->assertStringStartsNotWith('١', $extract['draft']['lines'][0]['name']);

        Cache::flush();
        $this->fakeGeminiDraft($this->fourHandwrittenLines());

        $pipeline = app(InvoiceOcrPipeline::class)->run($path);
        $this->assertTrue($pipeline['ok'], $pipeline['error'] ?? 'pipeline failed');
        $this->assertCount(4, $pipeline['draft']['lines'] ?? []);
        $this->assertSame([2, 3, 5, 7], array_map(
            fn ($l) => (int) round((float) $l['qty']),
            $pipeline['draft']['lines']
        ));

        foreach ($pipeline['draft']['lines'] as $line) {
            $this->assertContains($line['match_decision'] ?? null, ['manual', 'needs_choice']);
            $flags = (array) ($line['needs_review'] ?? []);
            $this->assertTrue(
                in_array('catalog_unmatched', $flags, true)
                || in_array('mg_family_unknown_variant', $flags, true)
                || in_array('needs_choice', $flags, true),
                'expected a catalog review flag'
            );
            $this->assertContains('price_missing', $flags);
        }
    }

    public function test_leading_list_number_not_used_as_qty(): void
    {
        $this->fakeGeminiDraft([
            ['raw_text' => '٢ وصله عاديه 1 بوصه', 'name' => '٢ وصله عاديه 1 بوصه', 'qty' => 5],
        ]);
        $path = $this->tinyJpegPath();
        $draft = app(GeminiClient::class)->extractInvoiceFromImage($path, 'image/jpeg')['draft'];
        $this->assertSame(2, $draft['lines'][0]['line_no']);
        $this->assertSame(5.0, (float) $draft['lines'][0]['qty']);
        $this->assertSame('وصله عاديه 1 بوصه', $draft['lines'][0]['name']);
    }

    public function test_truncated_max_tokens_is_not_cached(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'MAX_TOKENS',
                    'content' => ['parts' => [['text' => '']]],
                ]],
            ], 200),
        ]);

        $path = $this->tinyJpegPath();
        $result = app(InvoiceOcrPipeline::class)->run($path);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['cached']);
        $this->assertStringStartsWith('ocr:draft:v3:', InvoiceOcrPipeline::CACHE_PREFIX);

        // Second run must hit the network again (nothing was cached).
        $again = app(InvoiceOcrPipeline::class)->run($path);
        $this->assertFalse($again['ok']);
        $this->assertFalse($again['cached']);
        Http::assertSentCount(2);
    }

    public function test_cache_written_only_when_complete_stop(): void
    {
        $this->fakeGeminiDraft($this->fourHandwrittenLines(), 'STOP');
        $path = $this->tinyJpegPath();
        $first = app(InvoiceOcrPipeline::class)->run($path);
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['cached']);

        Http::fake(function () {
            $this->fail('HTTP must not run on cache hit');
        });

        $second = app(InvoiceOcrPipeline::class)->run($path);
        $this->assertTrue($second['cached']);
        $this->assertCount(4, $second['draft']['lines']);
    }

    public function test_empty_line_drop_records_reason(): void
    {
        $this->fakeGeminiDraft([
            ['raw_text' => 'بند', 'name' => 'بند', 'qty' => 1],
            ['raw_text' => '', 'name' => '', 'qty' => null],
        ]);
        $path = $this->tinyJpegPath();
        $draft = app(GeminiClient::class)->extractInvoiceFromImage($path, 'image/jpeg')['draft'];
        $this->assertCount(1, $draft['lines']);
        $this->assertSame(2, $draft['raw_line_count']);
        $this->assertSame(1, $draft['returned_line_count']);
        $this->assertSame(
            [['index' => 1, 'reason' => 'empty_text_and_qty']],
            $draft['dropped_reasons']
        );
    }

    public function test_price_less_lines_are_kept_with_needs_review(): void
    {
        $this->fakeGeminiDraft([
            [
                'raw_text' => 'كوع 1 بوصه',
                'name' => 'كوع 1 بوصه',
                'qty' => 3,
                'unit_price' => null,
                'line_total' => null,
            ],
        ]);
        $path = $this->tinyJpegPath();
        $pipeline = app(InvoiceOcrPipeline::class)->run($path);
        $this->assertTrue($pipeline['ok']);
        $this->assertCount(1, $pipeline['draft']['lines']);
        $this->assertContains('price_missing', $pipeline['draft']['lines'][0]['needs_review']);
        $this->assertSame(3, (int) round((float) $pipeline['draft']['lines'][0]['qty']));
    }

    /**
     * NIĞTAŞ notepad regression: unnumbered shopping list — Gemini often returns only row 1.
     * Completeness pass must still recover the remaining rows (not require leading 1. 2. 3.).
     */
    public function test_unnumbered_list_underread_triggers_completeness_for_all_rows(): void
    {
        $firstOnly = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'source_type' => 'handwritten',
                        'estimated_visible_rows' => 4,
                        'lines' => [[
                            'raw_text' => 'مثلث واي 4 بوصه 6',
                            'name' => 'مثلث واي 4 بوصه',
                            'qty' => 6,
                            'confidence' => 0.8,
                        ]],
                    ], JSON_UNESCAPED_UNICODE),
                ]]],
            ]],
        ];

        $allFour = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'source_type' => 'handwritten',
                        'estimated_visible_rows' => 4,
                        'lines' => [
                            ['raw_text' => 'مثلث واي 4 بوصه 6', 'name' => 'مثلث واي 4 بوصه', 'qty' => 6, 'confidence' => 0.8],
                            ['raw_text' => 'واي Mg 3 بوصه 10', 'name' => 'واي Mg 3 بوصه', 'qty' => 10, 'confidence' => 0.8],
                            ['raw_text' => 'مخفض 4-2 Mg 6', 'name' => 'مخفض 4-2 Mg', 'qty' => 6, 'confidence' => 0.8],
                            ['raw_text' => 'طوبو 4 بوصه 6', 'name' => 'طوبو 4 بوصه', 'qty' => 6, 'confidence' => 0.8],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ]]],
            ]],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($firstOnly)
                ->push($allFour)
                ->push($allFour),
        ]);

        $path = $this->tinyJpegPath();
        $pipeline = app(InvoiceOcrPipeline::class)->run($path);
        $this->assertTrue($pipeline['ok'], $pipeline['error'] ?? 'pipeline failed');

        $lines = $pipeline['draft']['lines'] ?? [];
        $excluded = $pipeline['draft']['excluded'] ?? [];
        $this->assertGreaterThanOrEqual(4, count($lines) + count($excluded));
        $this->assertGreaterThanOrEqual(4, (int) ($pipeline['draft']['raw_line_count'] ?? 0));

        $raws = collect($lines)->merge($excluded)->pluck('raw_text')->implode(' ');
        $this->assertStringContainsString('واي', $raws);
        $this->assertTrue(
            str_contains($raws, 'مخفض') || str_contains($raws, 'طوبو') || str_contains($raws, 'ماسورة'),
            'expected later notepad rows in draft, got: '.$raws
        );
    }
}

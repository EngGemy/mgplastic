<?php

namespace Tests\Feature;

use App\Models\OcrTermAlias;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Ocr\CatalogSlotParser;
use App\Services\Ocr\InvoiceOcrCatalogMatcher;
use App\Services\Ocr\Lexicon\WeightedEditDistance;
use App\Services\Ocr\OcrTermAliasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OcrLexiconTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_examples_resolve_as_specified(): void
    {
        $parser = app(CatalogSlotParser::class);

        $pipe = $parser->parse('طوبو ٤ بوصه');
        $this->assertSame('pipe', $pipe['family']);
        $this->assertSame(4.0, $pipe['size_value']);
        $this->assertSame('in', $pipe['size_unit']);
        $this->assertSame('us', $pipe['system']);
        $this->assertTrue($pipe['lexicon_auto_ok']);

        // Unconfirmed spellings (توبو / طوبوا) suggest only.
        foreach (['توبو 4 بوصة', 'طوبوا 4 بوصه'] as $raw) {
            $s = $parser->parse($raw);
            $this->assertSame('pipe', $s['family'], $raw);
            $this->assertSame(4.0, $s['size_value'], $raw);
            $this->assertSame('us', $s['system'], $raw);
            $this->assertFalse($s['lexicon_auto_ok'], $raw.' must not auto-accept unconfirmed');
        }

        // Repeated-letter handwriting طوبوو collapses onto confirmed طوبو → may auto-accept.
        $repeat = $parser->parse('طوبوو 4 بوصه');
        $this->assertSame('pipe', $repeat['family']);
        $this->assertSame(4.0, $repeat['size_value']);
        $this->assertTrue($repeat['lexicon_auto_ok']);

        $wye = $parser->parse('مثلث واي ٢ بوصه');
        $this->assertSame('wye', $wye['family']);
        $this->assertSame('y_tee', $wye['lexicon_family']);
        $this->assertNotSame('tee', $wye['family']);

        $amb = $parser->parse('مثلثات ٢ بوصه');
        $this->assertNull($amb['family']);
        $families = collect($amb['family_candidates'])->pluck('family')->all();
        $this->assertContains('tee', $families);
        $this->assertContains('y_tee', $families);

        $elbow = $parser->parse('أكواع 90 ١ بوصه');
        $this->assertSame('elbow_90', $elbow['family']);
        $this->assertSame(90.0, $elbow['angle']);
        $this->assertSame(1.0, $elbow['size_value']);
        $this->assertSame('in', $elbow['size_unit']);

        $coupling = $parser->parse('وصلات ١ ونص بوصه');
        $this->assertSame('coupling', $coupling['family']);
        $this->assertSame(1.5, $coupling['size_value']);

        $enElbow = $parser->parse('elbow 90 1 inch');
        $this->assertSame('elbow_90', $enElbow['family']);
        $this->assertSame(90.0, $enElbow['angle']);
        $this->assertSame(1.0, $enElbow['size_value']);

        $enTee = $parser->parse('tee 2 inch');
        $this->assertSame('tee', $enTee['family']);
        $this->assertSame(2.0, $enTee['size_value']);
    }

    public function test_phrase_first_prefers_mathalath_wye_over_tee(): void
    {
        $slots = app(CatalogSlotParser::class)->parse('مثلث واي 2 بوصه');
        $this->assertSame('wye', $slots['family']);
    }

    public function test_letter_confusion_fuzzy_distance_is_cheap(): void
    {
        $d = app(WeightedEditDistance::class);
        $this->assertLessThan(0.5, $d->distance('طوبو', 'توبو')); // ط/ت confusable group with ب و
        $this->assertLessThan(1.0, $d->distance('مخفض', 'محفض'));
    }

    public function test_pending_aliases_never_auto_accept(): void
    {
        OcrTermAlias::query()->create([
            'term_normalized' => 'fooze',
            'family_key' => 'pipe',
            'source' => 'user',
            'status' => 'pending',
            'hits' => 1,
            'distinct_users' => 1,
        ]);
        \App\Services\Ocr\Lexicon\OcrLexicon::clearIndexCache();

        $slots = app(CatalogSlotParser::class)->parse('fooze 4 بوصه');
        $this->assertSame('pipe', $slots['family']);
        $this->assertFalse($slots['lexicon_auto_ok']);
    }

    public function test_alias_promotion_requires_three_hits_from_two_users(): void
    {
        $service = app(OcrTermAliasService::class);
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        $a = $service->upsertPending('xyzpipe', 'pipe', $u1, 'xyzpipe 4', 'user');
        $this->assertSame('pending', $a->status);

        $service->upsertPending('xyzpipe', 'pipe', $u1, 'xyzpipe 4', 'user');
        $a = $service->upsertPending('xyzpipe', 'pipe', $u2, 'xyzpipe 4', 'user');
        $this->assertSame('approved', $a->fresh()->status);
        $this->assertGreaterThanOrEqual(3, (int) $a->fresh()->hits);
        $this->assertGreaterThanOrEqual(2, (int) $a->fresh()->distinct_users);
    }

    public function test_matcher_accounting_identity_and_no_silent_drop(): void
    {
        $cat = ProductCategory::query()->create(['slug' => 'us-'.uniqid()]);
        config(['ocr_catalog.category_system' => [$cat->id => 'us']]);

        $pipe = Product::query()->create([
            'sku' => 'P4',
            'product_category_id' => $cat->id,
            'is_active' => true,
            'pieces_per_box' => 10,
            'points_per_unit' => 1,
            'catalog_family' => 'pipe',
            'size_value' => 4,
            'size_unit' => 'in',
            'system' => 'us',
        ]);
        $pipe->translateOrNew('ar')->name = 'ماسورة 4 بوصة';
        $pipe->save();

        $draft = [
            'lines' => [
                ['raw_text' => 'طوبو ٤ بوصه', 'qty' => 1],
                ['raw_text' => 'مثلثات ٢ بوصه', 'qty' => 1],
                ['raw_text' => 'منتج غريب جدا', 'qty' => 1],
            ],
        ];

        $out = app(InvoiceOcrCatalogMatcher::class)->enrich($draft);
        $matched = count($out['lines'] ?? []);
        $excluded = count($out['excluded'] ?? []);
        $this->assertSame(3, ($out['match_counts']['raw'] ?? 0));
        $this->assertSame(
            ($out['match_counts']['matched'] ?? 0) + ($out['match_counts']['needs_choice'] ?? 0) + ($out['match_counts']['excluded'] ?? 0),
            $out['match_counts']['raw']
        );
        $this->assertSame($out['accounted_for'], $out['match_counts']['raw']);
        $this->assertSame(3, $matched + $excluded);
    }

    public function test_nothing_auto_carts_unconfirmed_dialect(): void
    {
        $cat = ProductCategory::query()->create(['slug' => 'us-'.uniqid()]);
        Product::query()->create([
            'sku' => 'P4b',
            'product_category_id' => $cat->id,
            'is_active' => true,
            'pieces_per_box' => 10,
            'points_per_unit' => 1,
            'catalog_family' => 'pipe',
            'size_value' => 4,
            'size_unit' => 'in',
            'system' => 'us',
        ]);

        $out = app(InvoiceOcrCatalogMatcher::class)->enrich([
            'lines' => [['raw_text' => 'توبو 4 بوصة', 'qty' => 1]],
        ]);

        $line = $out['lines'][0] ?? null;
        $this->assertNotNull($line);
        $this->assertNotSame('auto', $line['match_decision'] ?? null);
        $this->assertContains('unconfirmed_term', $line['needs_review'] ?? []);
        $this->assertNotEmpty($line['understanding'] ?? []);
    }

    public function test_unknown_terms_command_runs(): void
    {
        $this->assertSame(0, Artisan::call('ocr:unknown-terms', ['--days' => 30, '--min' => 2]));
    }
}

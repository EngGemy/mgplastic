<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Ocr\CatalogSlotParser;
use App\Services\Ocr\InvoiceOcrCatalogMatcher;
use App\Services\Ocr\InvoiceOcrLearningService;
use App\Support\ArabicTextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OcrStructuredMatchTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Product> */
    protected array $catalog = [];

    protected int $usCategoryId;

    protected int $euCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $usAcc = ProductCategory::query()->create(['slug' => 'us-acc-'.uniqid()]);
        $euAcc = ProductCategory::query()->create(['slug' => 'eu-acc-'.uniqid()]);
        $this->usCategoryId = (int) $usAcc->id;
        $this->euCategoryId = (int) $euAcc->id;
        config([
            'ocr_catalog.category_system' => [
                $this->usCategoryId => 'us',
                $this->euCategoryId => 'eu',
            ],
        ]);

        // Presentation-form style name (compat chars) for US elbow.
        $this->catalog['elbow_us'] = $this->makeProduct($usAcc->id, 'ﻛﻮع 90 – 1 ﺑﻮﺻﺔ MG', [
            'catalog_family' => 'elbow_90',
            'angle' => 90,
            'size_value' => 1,
            'size_unit' => 'in',
            'system' => 'us',
            'pieces_per_box' => 50,
            'points_per_unit' => 1.1,
        ]);
        $this->catalog['elbow_eu'] = $this->makeProduct($euAcc->id, 'كوع 90 – 1 بوصة MG', [
            'catalog_family' => 'elbow_90',
            'angle' => 90,
            'size_value' => 1,
            'size_unit' => 'in',
            'system' => 'eu',
            'pieces_per_box' => 40,
            'points_per_unit' => 1.2,
        ]);
        $this->catalog['coupling_us'] = $this->makeProduct($usAcc->id, 'وصلة عادية – 1 بوصة MG', [
            'catalog_family' => 'coupling',
            'size_value' => 1,
            'size_unit' => 'in',
            'system' => 'us',
            'pieces_per_box' => 100,
            'points_per_unit' => 0.5,
        ]);
        $this->catalog['coupling_eu'] = $this->makeProduct($euAcc->id, 'وصلة عادية – 1 بوصة MG', [
            'catalog_family' => 'coupling',
            'size_value' => 1,
            'size_unit' => 'in',
            'system' => 'eu',
            'pieces_per_box' => 100,
            'points_per_unit' => 0.55,
        ]);
        $this->catalog['thread_us'] = $this->makeProduct($usAcc->id, 'وصلة سن خارجي – 1 بوصة MG', [
            'catalog_family' => 'male_thread_coupling',
            'size_value' => 1,
            'size_unit' => 'in',
            'system' => 'us',
            'pieces_per_box' => 60,
            'points_per_unit' => 0.8,
        ]);
        $this->catalog['thread_eu'] = $this->makeProduct($euAcc->id, 'وصلة سن خارجي – 1 بوصة MG', [
            'catalog_family' => 'male_thread_coupling',
            'size_value' => 1,
            'size_unit' => 'in',
            'system' => 'eu',
            'pieces_per_box' => 60,
            'points_per_unit' => 0.85,
        ]);
        $this->catalog['end_us'] = $this->makeProduct($usAcc->id, 'نهاية خط – 1.5 بوصة MG', [
            'catalog_family' => 'end_cap',
            'size_value' => 1.5,
            'size_unit' => 'in',
            'system' => 'us',
            'pieces_per_box' => 30,
            'points_per_unit' => 0.7,
        ]);
        $this->catalog['tee_110'] = $this->makeProduct($euAcc->id, 'مثلث عادي - 110 بوصة MG', [
            'catalog_family' => 'tee',
            'size_value' => 110,
            'size_unit' => 'mm',
            'system' => 'eu',
            'pieces_per_box' => 10,
            'points_per_unit' => 2.5,
        ]);
        $this->catalog['elbow_3_us'] = $this->makeProduct($usAcc->id, 'كوع 90 – 3 بوصة MG', [
            'catalog_family' => 'elbow_90',
            'angle' => 90,
            'size_value' => 3,
            'size_unit' => 'in',
            'system' => 'us',
            'pieces_per_box' => 20,
            'points_per_unit' => 1.8,
        ]);
    }

    /** @param  array<string, mixed>  $attrs */
    protected function makeProduct(int $categoryId, string $arName, array $attrs): Product
    {
        $p = Product::query()->create(array_merge([
            'product_category_id' => $categoryId,
            'points_per_unit' => 1,
            'derived_at' => now(),
        ], $attrs));
        $p->translateOrNew('ar')->name = $arName;
        $p->translateOrNew('en')->name = 'EN '.$p->id;
        $p->save();

        return $p->fresh(['translations']);
    }

    public function test_presentation_form_catalog_matches_normal_ocr(): void
    {
        $normOcr = ArabicTextNormalizer::normalize('كوع 90 1 بوصه');
        $normCat = ArabicTextNormalizer::normalize('ﻛﻮع 90 – 1 ﺑﻮﺻﺔ MG');
        similar_text($normOcr, $normCat, $pct);
        $this->assertGreaterThan(85, $pct, "ocr=[$normOcr] cat=[$normCat]");
    }

    public function test_four_sample_lines_structured_outcomes(): void
    {
        $matcher = app(InvoiceOcrCatalogMatcher::class);
        $draft = $matcher->enrich([
            'lines' => [
                ['raw_text' => 'وصله عاديه ١ بوصه', 'qty' => 2],
                ['raw_text' => 'كوع ٩٠ ١ بوصه نظاف اتك', 'qty' => 3], // نظام امريك typo/noise
                ['raw_text' => 'مثلث عادي ١١٠', 'qty' => 1],
                ['raw_text' => 'قهوة', 'qty' => 1],
            ],
        ]);

        $this->assertSame(4, $draft['accounted_for']);
        $this->assertSame(
            $draft['match_counts']['matched'] + $draft['match_counts']['needs_choice'] + $draft['match_counts']['excluded'],
            $draft['accounted_for']
        );

        // Line 1: coupling size 1 → needs choice US/EU
        $l0 = $draft['lines'][0];
        $this->assertSame('coupling', $l0['slots']['family']);
        $this->assertSame(1.0, (float) $l0['slots']['size_value']);
        $this->assertNull($l0['slots']['system']);
        $this->assertSame('needs_choice', $l0['match_status']);
        $systems = collect($l0['candidates'])->pluck('system')->filter()->unique()->sort()->values()->all();
        $this->assertSame(['eu', 'us'], $systems);

        // Line 2: american from text (امريك/اتك)
        $l1 = collect($draft['lines'])->first(fn ($l) => str_contains((string) ($l['raw_text'] ?? ''), 'كوع'));
        $this->assertNotNull($l1);
        $this->assertSame('us', $l1['slots']['system']);
        $this->assertSame('matched', $l1['match_status']);
        $this->assertSame($this->catalog['elbow_us']->id, $l1['matched_product_id']);

        // Line 3: 110 → eu unique
        $l2 = collect($draft['lines'])->first(fn ($l) => str_contains((string) ($l['raw_text'] ?? ''), 'مثلث'));
        $this->assertNotNull($l2);
        $this->assertSame('eu', $l2['slots']['system']);
        $this->assertSame('matched', $l2['match_status']);
        $this->assertSame($this->catalog['tee_110']->id, $l2['matched_product_id']);

        // Line 4: قهوة excluded
        $this->assertCount(1, $draft['excluded']);
        $this->assertSame('قهوة', $draft['excluded'][0]['raw_text']);
        $this->assertSame('family_not_in_catalog', $draft['excluded'][0]['reason']);
    }

    public function test_end_cap_typo_and_thread_line(): void
    {
        $matcher = app(InvoiceOcrCatalogMatcher::class);
        $draft = $matcher->enrich([
            'lines' => [
                ['raw_text' => 'وصله سن خارجي ١ بوصه نظاف ارم', 'qty' => 5],
                ['raw_text' => 'نهية نظافه ١,٥ بوصه', 'qty' => 7],
            ],
        ]);

        $thread = $draft['lines'][0];
        $this->assertSame('male_thread_coupling', $thread['slots']['family']);
        // ارم → eu hint
        $this->assertSame('eu', $thread['slots']['system']);
        $this->assertSame('matched', $thread['match_status']);
        $this->assertSame($this->catalog['thread_eu']->id, $thread['matched_product_id']);

        $end = $draft['lines'][1];
        $this->assertSame('end_cap', $end['slots']['family']);
        $this->assertSame(1.5, (float) $end['slots']['size_value']);
        $this->assertSame('needs_choice', $end['match_status']); // 1.5 ambiguous without system word
    }

    public function test_bare_elbow_is_ambiguous_angle_or_size(): void
    {
        $slots = app(CatalogSlotParser::class)->parse('كوع ٩٠');
        $this->assertContains('ambiguous_angle_or_size', $slots['parse_notes']);
        $this->assertFalse($slots['parse_ok']);
    }

    public function test_three_inch_resolves_american_only(): void
    {
        $matcher = app(InvoiceOcrCatalogMatcher::class);
        $draft = $matcher->enrich([
            'lines' => [
                ['raw_text' => 'كوع ٩٠ ٣ بوصه', 'qty' => 2],
            ],
        ]);
        $line = $draft['lines'][0];
        $this->assertSame('us', $line['slots']['system']);
        $this->assertSame('matched', $line['match_status']);
        $this->assertSame($this->catalog['elbow_3_us']->id, $line['matched_product_id']);
    }

    public function test_angle_90_vs_mm_90_trap_on_catalog_parse(): void
    {
        $parser = app(CatalogSlotParser::class);
        $elbow = $parser->parseCatalogName('كوع 90 – 1 بوصة MG', $this->usCategoryId);
        $this->assertSame(90.0, $elbow['angle']);
        $this->assertSame(1.0, $elbow['size_value']);
        $this->assertSame('us', $elbow['system']);

        $mm = $parser->parseCatalogName('مثلث عادي - 90 بوصة MG', $this->euCategoryId);
        $this->assertNull($mm['angle']);
        $this->assertSame(90.0, $mm['size_value']);
        $this->assertSame('mm', $mm['size_unit']);
        $this->assertSame('eu', $mm['system']);
    }

    public function test_alias_learned_after_user_choice(): void
    {
        $learning = app(InvoiceOcrLearningService::class);
        $learning->rememberCorrection(
            'sha-test',
            'وصله عاديه ١ بوصه',
            $this->catalog['coupling_us']->id,
            2,
            null,
            'us',
            null,
            false,
        );

        $this->assertDatabaseHas('ocr_aliases', [
            'normalized_text' => ArabicTextNormalizer::normalize('وصله عاديه ١ بوصه'),
            'system_hint' => 'us',
            'product_id' => $this->catalog['coupling_us']->id,
        ]);

        $draft = app(InvoiceOcrCatalogMatcher::class)->enrich([
            'lines' => [
                ['raw_text' => 'وصله عاديه ١ بوصه', 'qty' => 2],
            ],
        ], 'us');

        $this->assertSame('alias', $draft['lines'][0]['match_decision']);
        $this->assertSame($this->catalog['coupling_us']->id, $draft['lines'][0]['matched_product_id']);
    }

    public function test_nothing_reaches_cart_without_approval_flag(): void
    {
        $draft = app(InvoiceOcrCatalogMatcher::class)->enrich([
            'lines' => [
                ['raw_text' => 'كوع ٩٠ ١ بوصه نظام امريك', 'qty' => 3],
            ],
        ]);
        $this->assertSame('matched', $draft['lines'][0]['match_status']);
        $this->assertArrayNotHasKey('approved', $draft['lines'][0]);
        $this->assertTrue(empty($draft['lines'][0]['approved']));
    }

    public function test_derive_attributes_dry_run_reports_without_writing(): void
    {
        $product = $this->makeProduct($this->usCategoryId, 'كوع 45 – 2 بوصة MG', [
            'catalog_family' => null,
            'size_value' => null,
            'system' => null,
            'derived_at' => null,
        ]);

        $this->artisan('products:derive-attributes')
            ->assertSuccessful();

        $product->refresh();
        $this->assertNull($product->catalog_family);

        $this->artisan('products:derive-attributes', ['--apply' => true])
            ->assertSuccessful();

        $product->refresh();
        $this->assertSame('elbow_45', $product->catalog_family);
        $this->assertSame(45.0, (float) $product->angle);
        $this->assertSame(2.0, (float) $product->size_value);
        $this->assertSame('us', $product->system);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Ocr\CatalogSlotParser;
use App\Services\Ocr\InvoiceOcrCatalogMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OcrUnknownNotNonMgTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Product> */
    protected array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog['wye_4'] = $this->makeProduct(59, 'wye', 4.0, 'in', 'us', 'مثلث واي 4 بوصة');
        $this->catalog['wye_3'] = $this->makeProduct(42, 'wye', 3.0, 'in', 'us', 'مثلث واي 3 بوصة');
        $this->catalog['reducer'] = $this->makeProduct(61, 'reducer', 4.0, 'in', 'us', 'مخفض من 4 الى 2', size2: 2.0);
        $this->catalog['pipe_plain'] = $this->makeProduct(108, 'pipe', 4.0, 'in', 'us', 'ماسورة 4 بوصة', variant: []);
        $this->catalog['pipe_silent'] = $this->makeProduct(114, 'pipe', 4.0, 'in', 'us', 'ماسورة 4 بوصة كاتم صوت', variant: ['layers_silent' => true]);
        $this->catalog['pipe_gold_86'] = $this->makeProduct(120, 'pipe', 4.0, 'in', 'us', 'ماسورة ذهبي PAR 8.6', variant: ['gold' => true]);
        $this->catalog['pipe_gold_11'] = $this->makeProduct(121, 'pipe', 4.0, 'in', 'us', 'ماسورة ذهبي PAR 11', variant: ['gold' => true]);
    }

    public function test_plumbing_without_mg_is_excluded_mg_forms_match(): void
    {
        $matcher = app(InvoiceOcrCatalogMatcher::class);
        $draft = $matcher->enrich([
            'lines' => [
                ['raw_text' => 'مثلثات واي 4 بوصة 6', 'qty' => 6],
                ['raw_text' => 'واي Mg 3 بوصة 10', 'qty' => 10],
                ['raw_text' => 'مخفض 4-2 ام جي 6', 'qty' => 6],
                ['raw_text' => 'طوبو 4 بوصة 6', 'qty' => 6],
                ['raw_text' => 'ماسورة 4 بوصة امجي', 'qty' => 2],
            ],
        ], 'us');

        $this->assertSame(
            5,
            ($draft['match_counts']['matched'] ?? 0)
            + ($draft['match_counts']['needs_choice'] ?? 0)
            + ($draft['match_counts']['excluded'] ?? 0)
        );

        $excludedRaws = collect($draft['excluded'])->pluck('raw_text')->all();
        $this->assertContains('مثلثات واي 4 بوصة 6', $excludedRaws);
        $this->assertContains('طوبو 4 بوصة 6', $excludedRaws);
        $this->assertSame(
            'plumbing_without_mg',
            collect($draft['excluded'])->firstWhere('raw_text', 'مثلثات واي 4 بوصة 6')['reason'] ?? null
        );
        $this->assertSame(
            'بدون علامة MG',
            collect($draft['excluded'])->firstWhere('raw_text', 'مثلثات واي 4 بوصة 6')['exclude_label'] ?? null
        );

        $byRaw = collect($draft['lines'])->keyBy(fn ($l) => (string) ($l['raw_text'] ?? ''));

        $y3 = $byRaw['واي Mg 3 بوصة 10'];
        $this->assertSame('wye', $y3['slots']['family']);
        $this->assertTrue((bool) ($y3['slots']['mg_brand'] ?? false));
        $this->assertSame('matched', $y3['match_status']);
        $this->assertSame($this->catalog['wye_3']->id, $y3['matched_product_id']);

        $red = $byRaw['مخفض 4-2 ام جي 6'];
        $this->assertSame('reducer', $red['slots']['family']);
        $this->assertTrue((bool) ($red['slots']['mg_brand'] ?? false));
        $this->assertSame('matched', $red['match_status']);
        $this->assertSame($this->catalog['reducer']->id, $red['matched_product_id']);

        $pipeMg = $byRaw['ماسورة 4 بوصة امجي'];
        $this->assertSame('pipe', $pipeMg['slots']['family']);
        $this->assertTrue((bool) ($pipeMg['slots']['mg_brand'] ?? false));
        $this->assertSame('needs_choice', $pipeMg['match_status']);
    }

    public function test_qahwa_and_unmarked_faucet_and_rival_brand_excluded(): void
    {
        $matcher = app(InvoiceOcrCatalogMatcher::class);
        $draft = $matcher->enrich([
            'lines' => [
                ['raw_text' => 'قهوة', 'qty' => 1],
                ['raw_text' => 'حنفية 1 بوصة', 'qty' => 2],
                ['raw_text' => 'NIĞTAŞ', 'qty' => null],
                ['raw_text' => 'حنفية 1 بوصة MG', 'qty' => 1, 'brand' => 'MG'],
            ],
        ]);

        $q = collect($draft['excluded'])->firstWhere('raw_text', 'قهوة');
        $this->assertNotNull($q);
        $this->assertSame('known_non_mg', $q['reason']);
        $this->assertSame('مش منتج MG', $q['exclude_label']);

        $faucet = collect($draft['excluded'])->firstWhere('raw_text', 'حنفية 1 بوصة');
        $this->assertNotNull($faucet);
        $this->assertSame('plumbing_without_mg', $faucet['reason']);
        $this->assertSame('بدون علامة MG', $faucet['exclude_label']);

        $header = collect($draft['excluded'])->first(fn ($e) => str_contains(mb_strtolower((string) ($e['raw_text'] ?? '')), 'nig')
            || str_contains((string) ($e['raw_text'] ?? ''), 'NIĞ'));
        $this->assertNotNull($header, 'letterhead without plumbing signal is excluded');

        $faucetMg = collect($draft['lines'])->firstWhere('raw_text', 'حنفية 1 بوصة MG');
        $this->assertNotNull($faucetMg);
        $this->assertTrue((bool) ($faucetMg['slots']['mg_brand'] ?? false));
        $this->assertSame('unknown_word', $faucetMg['match_status']);
        $this->assertContains('unknown_word', $faucetMg['needs_review']);

        $accounted = count($draft['lines']) + count($draft['excluded']);
        $this->assertSame(4, $accounted);
        $this->assertSame(
            $accounted,
            ($draft['match_counts']['matched'] ?? 0)
            + ($draft['match_counts']['needs_choice'] ?? 0)
            + ($draft['match_counts']['excluded'] ?? 0)
        );
    }

    public function test_parser_detects_mg_spellings(): void
    {
        $parser = app(CatalogSlotParser::class);

        foreach (['Mg', 'M.G', 'ام جي', 'امجي', 'إم جي', 'MG Plastic'] as $mark) {
            $slots = $parser->parse('ماسورة 4 بوصة '.$mark);
            $this->assertTrue((bool) $slots['mg_brand'], "failed for spelling: {$mark}");
        }

        $bare = $parser->parse('ماسورة 4 بوصة');
        $this->assertFalse((bool) $bare['mg_brand']);

        $viaBrand = $parser->parse('ماسورة 4 بوصة', 'ام جي');
        $this->assertTrue((bool) $viaBrand['mg_brand']);
    }

    public function test_parser_does_not_steal_qty_as_size2(): void
    {
        $slots = app(CatalogSlotParser::class)->parse('طوبو 4 بوصة 6');
        $this->assertSame(4.0, $slots['size_value']);
        $this->assertNull($slots['size2_value']);
    }

    /**
     * @param  array<string,mixed>  $variant
     */
    protected function makeProduct(
        int $id,
        string $family,
        float $size,
        string $unit,
        ?string $system,
        string $nameAr,
        ?float $size2 = null,
        array $variant = [],
    ): Product {
        $p = Product::factory()->create([
            'id' => $id,
            'catalog_family' => $family,
            'size_value' => $size,
            'size_unit' => $unit,
            'size2_value' => $size2,
            'system' => $system,
            'variant' => $variant,
            'angle' => null,
            'derived_at' => now(),
            'points_per_unit' => 1,
        ]);
        $p->translateOrNew('ar')->name = $nameAr;
        $p->save();

        return $p->fresh(['translations']);
    }
}

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

    public function test_four_handwritten_lines_roles(): void
    {
        $matcher = app(InvoiceOcrCatalogMatcher::class);
        $draft = $matcher->enrich([
            'lines' => [
                ['raw_text' => 'مثلثات واي 4 بوصة 6', 'qty' => 6],
                ['raw_text' => 'واي Mg 3 بوصة 10', 'qty' => 10],
                ['raw_text' => 'مخفض 4-2 Mg 6', 'qty' => 6],
                ['raw_text' => 'طوبو 4 بوصة 6', 'qty' => 6],
            ],
        ], 'us');

        $this->assertSame(4, ($draft['match_counts']['matched'] ?? 0) + ($draft['match_counts']['needs_choice'] ?? 0) + ($draft['match_counts']['excluded'] ?? 0));
        $this->assertCount(0, $draft['excluded']);

        $byRaw = collect($draft['lines'])->keyBy(fn ($l) => (string) ($l['raw_text'] ?? ''));

        $y4 = $byRaw['مثلثات واي 4 بوصة 6'];
        $this->assertSame('wye', $y4['slots']['family']);
        $this->assertSame(4.0, (float) $y4['slots']['size_value']);
        $this->assertNull($y4['slots']['size2_value']);
        $this->assertSame(6.0, (float) $y4['qty']);
        $this->assertSame('matched', $y4['match_status']);
        $this->assertSame($this->catalog['wye_4']->id, $y4['matched_product_id']);
        $this->assertSame([], $y4['needs_review'] ?? []);

        $y3 = $byRaw['واي Mg 3 بوصة 10'];
        $this->assertSame('wye', $y3['slots']['family']);
        $this->assertTrue((bool) ($y3['slots']['mg_brand'] ?? false));
        $this->assertSame('matched', $y3['match_status']);
        $this->assertSame($this->catalog['wye_3']->id, $y3['matched_product_id']);
        $this->assertSame([], $y3['needs_review'] ?? []);

        $red = $byRaw['مخفض 4-2 Mg 6'];
        $this->assertSame('reducer', $red['slots']['family']);
        $this->assertSame(4.0, (float) $red['slots']['size_value']);
        $this->assertSame(2.0, (float) $red['slots']['size2_value']);
        $this->assertSame('matched', $red['match_status']);
        $this->assertSame($this->catalog['reducer']->id, $red['matched_product_id']);
        $this->assertSame([], $red['needs_review'] ?? [], 'unique reducer must be ready (empty needs_review)');

        $pipe = $byRaw['طوبو 4 بوصة 6'];
        $this->assertSame('pipe', $pipe['slots']['family']);
        $this->assertSame(4.0, (float) $pipe['slots']['size_value']);
        $this->assertSame('needs_choice', $pipe['match_status']);
        $this->assertNull($pipe['matched_product_id']);
        $candIds = collect($pipe['candidates'])->pluck('product_id')->all();
        $this->assertSame($this->catalog['pipe_plain']->id, $candIds[0] ?? null, 'plain pipe ranks first');
        $this->assertContains($this->catalog['pipe_silent']->id, $candIds);
        $this->assertContains($this->catalog['pipe_gold_86']->id, $candIds);
    }

    public function test_qahwa_excluded_faucet_with_unit_needs_review(): void
    {
        $matcher = app(InvoiceOcrCatalogMatcher::class);
        $draft = $matcher->enrich([
            'lines' => [
                ['raw_text' => 'قهوة', 'qty' => 1],
                ['raw_text' => 'حنفية 1 بوصة', 'qty' => 2],
                ['raw_text' => 'NIĞTAŞ', 'qty' => null],
            ],
        ]);

        $this->assertGreaterThanOrEqual(2, count($draft['excluded']));
        $q = collect($draft['excluded'])->firstWhere('raw_text', 'قهوة');
        $this->assertNotNull($q);
        $this->assertSame('known_non_mg', $q['reason']);
        $this->assertSame('مش منتج MG', $q['exclude_label']);

        $header = collect($draft['excluded'])->first(fn ($e) => str_contains(mb_strtolower((string) ($e['raw_text'] ?? '')), 'nig')
            || str_contains((string) ($e['raw_text'] ?? ''), 'NIĞ'));
        $this->assertNotNull($header, 'letterhead without plumbing signal is excluded');

        $faucet = collect($draft['lines'])->firstWhere('raw_text', 'حنفية 1 بوصة');
        $this->assertNotNull($faucet);
        $this->assertSame('unknown_word', $faucet['match_status']);
        $this->assertContains('unknown_word', $faucet['needs_review']);
        $this->assertSame('كلمة غير معروفة', $faucet['match_reason']);
        $this->assertNotEmpty($faucet['family_chips'] ?? []);

        $accounted = count($draft['lines']) + count($draft['excluded']);
        $this->assertSame(3, $accounted);
        $this->assertSame(
            $accounted,
            ($draft['match_counts']['matched'] ?? 0)
            + ($draft['match_counts']['needs_choice'] ?? 0)
            + ($draft['match_counts']['excluded'] ?? 0)
        );
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

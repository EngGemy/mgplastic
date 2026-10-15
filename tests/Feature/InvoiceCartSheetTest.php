<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\InvoiceCartSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class InvoiceCartSheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet_quantities_fill_boxes_and_loose_units(): void
    {
        $accessory = Product::factory()->create([
            'points_per_unit' => 5,
            'pieces_per_box' => 12,
        ]);
        $accessory->translateOrNew('ar')->name = 'كوع';
        $accessory->save();

        $pipe = Product::factory()->create([
            'points_per_unit' => 2,
            'pieces_per_box' => null,
        ]);
        $pipe->translateOrNew('ar')->name = 'ماسورة';
        $pipe->save();

        $path = $this->sheet([
            [$accessory->id, 'كوع', 3],
            ['', 'ماسورة', 4],
            [$accessory->id, '', 1],
            [99999, 'ناقص', 2],
        ]);

        $result = app(InvoiceCartSheetService::class)->import($path);

        $this->assertCount(2, $result['lines']);
        $this->assertNotEmpty($result['errors']);

        $boxed = collect($result['lines'])->firstWhere('product_id', $accessory->id);
        $loose = collect($result['lines'])->firstWhere('product_id', $pipe->id);

        $this->assertSame(4, $boxed['boxes']);
        $this->assertSame(48, $boxed['quantity']);
        $this->assertSame(12, $boxed['pieces_per_box']);
        $this->assertSame(4, $loose['quantity']);
        $this->assertNull($loose['boxes']);

        @unlink($path);
    }

    /**
     * @param  list<array{0: int|string, 1: string, 2: int}>  $rows
     */
    private function sheet(array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['رقم المنتج', 'اسم المنتج', 'الكمية'], null, 'A1');
        $line = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A'.$line);
            $line++;
        }

        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/invoice-cart-'.uniqid('', true).'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}

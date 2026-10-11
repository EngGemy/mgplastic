<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ProductSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductSheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_lists_each_category_number(): void
    {
        $category = ProductCategory::factory()->create();

        $path = app(ProductSheetService::class)->templatePath();
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName(ProductSheetService::CATEGORIES_SHEET);

        $this->assertNotNull($sheet);
        $this->assertSame($category->id, (int) $sheet->getCell('A2')->getValue());
        $this->assertSame('رقم التصنيف', $book->getSheetByName(ProductSheetService::PRODUCTS_SHEET)->getCell('B1')->getValue());

        @unlink($path);
    }

    public function test_import_creates_and_updates_products(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/main/pipe.jpg', 'image');

        $category = ProductCategory::factory()->create();
        $existing = Product::factory()->create([
            'product_category_id' => $category->id,
            'points_per_unit' => 2,
            'point_value_type' => 'fixed',
            'point_value_fixed' => 0.2,
            'classification' => 'standard',
            'notes' => 'قديم',
        ]);
        $englishName = $existing->translate('en')->name;

        $path = $this->sheet([
            [
                'category_id' => $category->id,
                'name_ar' => 'ماسورة جديدة',
                'name_en' => 'New pipe',
                'points_per_unit' => 3,
                'point_value_type' => 'نسبة',
                'point_value_percent' => 1.5,
                'reference_unit_price' => 10,
                'classification' => 'premium',
                'image' => 'pipe.jpg',
            ],
            [
                'id' => $existing->id,
                'category_id' => $category->id,
                'name_ar' => 'ماسورة محدّثة',
                'points_per_unit' => 4,
                'notes' => '-',
            ],
        ]);

        $result = app(ProductSheetService::class)->import($path);

        $this->assertSame([], $result['errors'], implode(' | ', $result['errors']));
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);

        $created = Product::query()->whereKeyNot($existing->id)->first();
        $this->assertNotNull($created);
        $this->assertSame('ماسورة جديدة', $created->translate('ar')->name);
        $this->assertSame('New pipe', $created->translate('en')->name);
        $this->assertSame('percent', $created->point_value_type);
        $this->assertSame(1000, $created->reference_unit_price_cents);
        $this->assertSame('products/main/pipe.jpg', $created->main_image);
        $this->assertEquals(1.5, (float) $created->point_value_percent);

        $existing->refresh();
        $this->assertSame('ماسورة محدّثة', $existing->translate('ar')->name);
        $this->assertSame($englishName, $existing->translate('en')->name);
        $this->assertNull($existing->notes);
        $this->assertSame('standard', $existing->classification);
        $this->assertEquals(4, (float) $existing->points_per_unit);

        @unlink($path);
    }

    public function test_import_rejects_unknown_category_without_creating_a_product(): void
    {
        $path = $this->sheet([
            [
                'category_id' => 999999,
                'name_ar' => 'منتج',
                'points_per_unit' => 1,
                'point_value_type' => 'ثابت',
                'point_value_fixed' => 0.1,
            ],
        ]);

        $result = app(ProductSheetService::class)->import($path);

        $this->assertSame(0, $result['created']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, Product::count());

        @unlink($path);
    }

    public function test_export_round_trip_updates_the_same_product(): void
    {
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create([
            'product_category_id' => $category->id,
            'points_per_unit' => 5,
            'point_value_type' => 'fixed',
            'point_value_fixed' => 0.25,
            'classification' => 'premium',
            'notes' => 'ملاحظة',
        ]);
        $product->translateOrNew('ar')->name = 'ماسورة';
        $product->translateOrNew('en')->name = 'Pipe';
        $product->save();

        $service = app(ProductSheetService::class);
        $path = $service->exportPath(Product::query()->whereKey($product->id));
        $result = $service->import($path);

        $this->assertSame([], $result['errors'], implode(' | ', $result['errors']));
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, Product::count());

        $product->refresh();
        $this->assertSame('ماسورة', $product->translate('ar')->name);
        $this->assertSame('Pipe', $product->translate('en')->name);
        $this->assertSame('premium', $product->classification);
        $this->assertSame('ملاحظة', $product->notes);
        $this->assertEquals(5, (float) $product->points_per_unit);

        @unlink($path);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sheet(array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $headers = array_map(fn (array $labels) => $labels[0], ProductSheetService::COLUMNS);
        $sheet->fromArray(array_values($headers), null, 'A1');

        $line = 2;
        foreach ($rows as $row) {
            $values = [];
            foreach (array_keys(ProductSheetService::COLUMNS) as $key) {
                $values[] = $row[$key] ?? null;
            }
            $sheet->fromArray($values, null, 'A'.$line);
            $line++;
        }

        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/products-'.uniqid('', true).'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}

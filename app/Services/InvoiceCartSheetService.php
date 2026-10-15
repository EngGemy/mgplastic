<?php

namespace App\Services;

use App\Models\Product;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class InvoiceCartSheetService
{
    /**
     * @var array<string, list<string>>
     */
    public const COLUMNS = [
        'product_id' => ['رقم المنتج', 'id', 'product_id'],
        'name' => ['اسم المنتج', 'الاسم', 'name'],
        'quantity' => ['الكمية', 'عدد الصناديق', 'quantity'],
    ];

    public function templatePath(): string
    {
        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.'/invoice-lines-'.uniqid('', true).'.xlsx';
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('بنود فاتورة MG')->setCreator('MG Plastic');

        $sheet = $book->getActiveSheet();
        $sheet->setTitle('الفاتورة');
        $sheet->setRightToLeft(true);
        $headers = ['رقم المنتج', 'اسم المنتج', 'الكمية'];
        foreach ($headers as $index => $header) {
            $cell = Coordinate::stringFromColumnIndex($index + 1).'1';
            $sheet->setCellValue($cell, $header);
        }
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A56DB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(36);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->freezePane('A2');

        $this->writeProductLookup($book->createSheet());
        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /**
     * @return array{lines: list<array<string, mixed>>, errors: list<string>}
     */
    public function import(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('ملف الشيت غير موجود.');
        }

        try {
            $book = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('تعذّر قراءة الشيت. استخدم ملف إكسل من نموذج الفاتورة.');
        }

        $found = $this->findHeader($book);
        if ($found === null) {
            throw new RuntimeException('الشيت لازم فيه عمود «رقم المنتج» وعمود «الكمية». نزّل النموذج وعبّيه.');
        }

        [$sheet, $headerRow, $columns] = $found;
        $grouped = [];
        $errors = [];
        $highest = min($sheet->getHighestDataRow(), 5000);

        for ($row = $headerRow + 1; $row <= $highest; $row++) {
            $values = $this->readRow($sheet, $row, $columns);
            if ($this->rowIsEmpty($values)) {
                continue;
            }

            $product = $this->resolveProduct($values, $row, $errors);
            $amount = $this->amount($values['quantity'] ?? '', $row, $errors);
            if (! $product || $amount === null) {
                continue;
            }

            $id = (int) $product->id;
            if (! isset($grouped[$id])) {
                $grouped[$id] = ['product' => $product, 'amount' => 0];
            }
            $grouped[$id]['amount'] += $amount;
        }

        $lines = [];
        foreach ($grouped as $group) {
            $lines[] = $this->cartLine($group['product'], $group['amount']);
        }

        return ['lines' => $lines, 'errors' => $errors];
    }

    /**
     * @return array<string, mixed>
     */
    public function cartLine(Product $product, int $amount): array
    {
        $pieces = (int) ($product->pieces_per_box ?? 0);
        $boxed = $pieces > 0;

        return [
            'product_id' => (int) $product->id,
            'name' => localized_name($product, 'name', 'منتج #'.$product->id),
            'quantity' => $boxed ? $amount * $pieces : $amount,
            'boxes' => $boxed ? $amount : null,
            'pieces_per_box' => $boxed ? $pieces : null,
            'points_per_unit' => (float) ($product->points_per_unit ?? 0),
            'image' => $product->main_image,
        ];
    }

    private function writeProductLookup(Worksheet $sheet): void
    {
        $sheet->setTitle('المنتجات');
        $sheet->setRightToLeft(true);
        foreach (['رقم المنتج', 'اسم المنتج', 'عدد الوحدات في الصندوق'] as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A56DB']],
        ]);
        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(36);
        $sheet->getColumnDimension('C')->setWidth(26);
        $sheet->freezePane('A2');

        $row = 2;
        Product::query()
            ->with('translations')
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($sheet, &$row) {
                foreach ($products as $product) {
                    $sheet->setCellValue("A{$row}", $product->id);
                    $sheet->setCellValue("B{$row}", $product->translate('ar')?->name);
                    $sheet->setCellValue("C{$row}", $product->pieces_per_box);
                    $row++;
                }
            });
    }

    /**
     * @return array{0: Worksheet, 1: int, 2: array<int, string>}|null
     */
    private function findHeader(Spreadsheet $book): ?array
    {
        foreach ($book->getWorksheetIterator() as $sheet) {
            $highest = min($sheet->getHighestDataRow(), 8);
            $maxCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn() ?: 'A'), 20);
            for ($row = 1; $row <= $highest; $row++) {
                $columns = [];
                for ($col = 1; $col <= $maxCol; $col++) {
                    $key = $this->headerKey($this->cellString($sheet->getCell([$col, $row])->getValue()));
                    if ($key) {
                        $columns[$col] = $key;
                    }
                }
                if (in_array('quantity', $columns, true) && (in_array('product_id', $columns, true) || in_array('name', $columns, true))) {
                    return [$sheet, $row, $columns];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<string, string>
     */
    private function readRow(Worksheet $sheet, int $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $col => $key) {
            $values[$key] = $this->cellString($sheet->getCell([$col, $row])->getValue());
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     */
    private function resolveProduct(array $values, int $row, array &$errors): ?Product
    {
        $id = trim($values['product_id'] ?? '');
        if ($id !== '' && ctype_digit($id)) {
            $product = Product::query()->with('translations')->find((int) $id);
            if (! $product) {
                $errors[] = "صف {$row}: رقم المنتج {$id} غير موجود.";

                return null;
            }

            return $product;
        }

        $name = trim($values['name'] ?? '');
        if ($name === '') {
            $errors[] = "صف {$row}: اكتب رقم المنتج أو اسمه.";

            return null;
        }

        $matches = Product::query()
            ->with('translations')
            ->whereHas('translations', fn ($q) => $q->where('locale', 'ar')->where('name', $name))
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $errors[] = $matches->isEmpty()
            ? "صف {$row}: لا يوجد منتج باسم «{$name}»."
            : "صف {$row}: الاسم «{$name}» مكرر. استخدم رقم المنتج.";

        return null;
    }

    /**
     * @param  list<string>  $errors
     */
    private function amount(string $raw, int $row, array &$errors): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || ! preg_match('/^\d+$/', $raw) || (int) $raw < 1) {
            $errors[] = "صف {$row}: الكمية يجب أن تكون رقماً صحيحاً أكبر من صفر.";

            return null;
        }

        return (int) $raw;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function headerKey(string $label): ?string
    {
        $label = mb_strtolower(trim($label));
        $label = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $label);
        if ($label === '') {
            return null;
        }

        foreach (self::COLUMNS as $key => $aliases) {
            foreach ($aliases as $alias) {
                $alias = mb_strtolower(trim($alias));
                $alias = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $alias);
                if ($label === $alias) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function cellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || (is_float($value) && floor($value) == $value)) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }
}

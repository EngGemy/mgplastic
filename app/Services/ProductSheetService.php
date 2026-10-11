<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ProductSheetService
{
    public const PRODUCTS_SHEET = 'المنتجات';

    public const CATEGORIES_SHEET = 'التصنيفات';

    /**
     * أول تسمية هي عنوان العمود في الشيت.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNS = [
        'id' => ['رقم المنتج', 'id'],
        'category_id' => ['رقم التصنيف', 'category_id'],
        'category_name' => ['اسم التصنيف (للعرض)', 'category_name'],
        'name_ar' => ['الاسم عربي', 'name_ar'],
        'name_en' => ['الاسم إنجليزي', 'name_en'],
        'usage_ar' => ['الاستخدام عربي', 'usage_ar'],
        'usage_en' => ['الاستخدام إنجليزي', 'usage_en'],
        'description_ar' => ['الوصف عربي', 'description_ar'],
        'description_en' => ['الوصف إنجليزي', 'description_en'],
        'classification' => ['الدرجة', 'classification'],
        'length_m' => ['الطول (م)', 'length_m'],
        'thickness_mm' => ['السمك (مم)', 'thickness_mm'],
        'volume_ml' => ['الحجم (مل)', 'volume_ml'],
        'points_per_unit' => ['النقاط لكل وحدة', 'points_per_unit'],
        'point_value_type' => ['نوع التحويل', 'point_value_type'],
        'point_value_percent' => ['نسبة النقاط', 'point_value_percent'],
        'point_value_fixed' => ['قيمة النقطة', 'point_value_fixed'],
        'reference_unit_price' => ['سعر الوحدة (د.ل)', 'reference_unit_price'],
        'notes' => ['ملاحظات', 'notes'],
        'image' => ['الصورة', 'image'],
    ];

    /** @var list<string> */
    private const READONLY = ['category_name'];

    public function templatePath(): string
    {
        return $this->writeWorkbook(null);
    }

    public function exportPath(?Builder $query = null): string
    {
        return $this->writeWorkbook($query ?? Product::query());
    }

    /**
     * @return array{created: int, updated: int, errors: list<string>, warnings: list<string>}
     */
    public function import(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('ملف الشيت غير موجود.');
        }

        try {
            $book = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('تعذّر قراءة الشيت. استخدم ملف Excel من نموذج المنتجات.');
        }

        $found = $this->findProductsSheet($book);
        if ($found === null) {
            throw new RuntimeException('الشيت لا يحتوي عناوين النموذج. نزّل «نموذج الشيت» وعبّئ ورقة المنتجات.');
        }

        [$sheet, $headerRow, $columns] = $found;

        $result = [
            'created' => 0,
            'updated' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        $highest = min($sheet->getHighestDataRow(), 5000);

        for ($row = $headerRow + 1; $row <= $highest; $row++) {
            $values = $this->readRow($sheet, $row, $columns);
            if ($this->rowIsEmpty($values)) {
                continue;
            }

            $errors = [];
            $warnings = [];

            try {
                $saved = $this->importRow($values, $errors, $warnings);
            } catch (\Throwable $e) {
                report($e);
                $result['errors'][] = "صف {$row}: تعذّر حفظ المنتج.";

                continue;
            }

            foreach ($warnings as $warning) {
                $result['warnings'][] = "صف {$row}: {$warning}";
            }

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $result['errors'][] = "صف {$row}: {$error}";
                }

                continue;
            }

            if ($saved === 'created') {
                $result['created']++;
            } elseif ($saved === 'updated') {
                $result['updated']++;
            }
        }

        return $result;
    }

    private function writeWorkbook(?Builder $products): string
    {
        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.'/products-'.uniqid('', true).'.xlsx';
        $book = new Spreadsheet;
        $book->getProperties()
            ->setTitle('منتجات MG Plastic')
            ->setCreator('MG Plastic');

        $productsSheet = $book->getActiveSheet();
        $productsSheet->setTitle(self::PRODUCTS_SHEET);
        $this->styleSheet($productsSheet);
        $this->writeProductHeader($productsSheet);

        $row = 2;
        if ($products) {
            $products->clone()
                ->reorder()
                ->with(['translations', 'category.translations', 'category.parent.translations'])
                ->orderBy('id')
                ->chunkById(200, function ($chunk) use ($productsSheet, &$row) {
                    foreach ($chunk as $product) {
                        $this->writeProductRow($productsSheet, $row, $product);
                        $row++;
                    }
                });
        }

        $lastCategoryRow = $this->writeCategoriesSheet($book->createSheet());
        $this->writeInstructionsSheet($book->createSheet());
        $this->applyCategoryDropdown($productsSheet, $lastCategoryRow);

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function writeProductHeader(Worksheet $sheet): void
    {
        $col = 1;
        foreach (self::COLUMNS as $key => $labels) {
            $cell = Coordinate::stringFromColumnIndex($col).'1';
            $sheet->setCellValue($cell, $labels[0]);
            if (in_array($key, self::READONLY, true)) {
                $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF9CA3AF');
            }
            $col++;
        }

        $lastCol = Coordinate::stringFromColumnIndex(count(self::COLUMNS));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial', 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A56DB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastCol}1");

        $widths = [14, 16, 28, 28, 28, 24, 24, 36, 36, 16, 12, 12, 12, 16, 14, 14, 14, 18, 28, 32];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
    }

    private function writeProductRow(Worksheet $sheet, int $row, Product $product): void
    {
        $category = $product->category;
        $values = [
            'id' => $product->id,
            'category_id' => $product->product_category_id,
            'category_name' => $category ? $this->categoryLabel($category) : '',
            'name_ar' => $product->translate('ar')?->name,
            'name_en' => $product->translate('en')?->name,
            'usage_ar' => $product->translate('ar')?->usage,
            'usage_en' => $product->translate('en')?->usage,
            'description_ar' => $this->plainText($product->translate('ar')?->description),
            'description_en' => $this->plainText($product->translate('en')?->description),
            'classification' => $product->classification,
            'length_m' => $product->length_m,
            'thickness_mm' => $product->thickness_mm,
            'volume_ml' => $product->volume_ml,
            'points_per_unit' => $product->points_per_unit,
            'point_value_type' => match ($product->point_value_type) {
                'percent' => 'نسبة',
                'fixed' => 'ثابت',
                default => '',
            },
            'point_value_percent' => $product->point_value_percent,
            'point_value_fixed' => $product->point_value_fixed,
            'reference_unit_price' => $product->reference_unit_price_cents
                ? round($product->reference_unit_price_cents / 100, 2)
                : null,
            'notes' => $product->notes,
            'image' => $product->main_image,
        ];

        $col = 1;
        foreach (self::COLUMNS as $key => $labels) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$row, $values[$key]);
            $col++;
        }
    }

    private function writeCategoriesSheet(Worksheet $sheet): int
    {
        $sheet->setTitle(self::CATEGORIES_SHEET);
        $this->styleSheet($sheet);
        $headers = ['رقم التصنيف', 'التصنيف الأب', 'الاسم عربي', 'الاسم إنجليزي', 'مسار الصورة', 'شكل التصنيف'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }
        $sheet->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF059669']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        foreach ([18, 28, 28, 28, 36, 18] as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        $sheet->freezePane('A2');

        $categories = ProductCategory::query()
            ->with(['translations', 'parent.translations'])
            ->orderBy('parent_id')
            ->orderBy('id')
            ->get();

        $row = 2;
        foreach ($categories as $category) {
            $sheet->setCellValue("A{$row}", $category->id);
            $sheet->setCellValue("B{$row}", $category->parent ? $this->categoryLabel($category->parent) : '—');
            $sheet->setCellValue("C{$row}", $category->translate('ar')?->name);
            $sheet->setCellValue("D{$row}", $category->translate('en')?->name);
            $sheet->setCellValue("E{$row}", $category->image);
            $this->embedCategoryImage($sheet, $category, $row);
            $sheet->getRowDimension($row)->setRowHeight(42);
            $row++;
        }

        $last = max(2, $row - 1);
        $sheet->getStyle("A1:F{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        return $last;
    }

    private function embedCategoryImage(Worksheet $sheet, ProductCategory $category, int $row): void
    {
        $relative = ltrim((string) $category->image, '/');
        if ($relative === '' || ! Storage::disk('public')->exists($relative)) {
            $sheet->setCellValue("F{$row}", 'بدون صورة');

            return;
        }

        $absolute = Storage::disk('public')->path($relative);
        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return;
        }

        if (! is_file($absolute) || filesize($absolute) > 1_500_000) {
            return;
        }

        try {
            $drawing = new Drawing;
            $drawing->setName('تصنيف '.$category->id);
            $drawing->setPath($absolute);
            $drawing->setCoordinates("F{$row}");
            $drawing->setHeight(36);
            $drawing->setOffsetX(8);
            $drawing->setOffsetY(4);
            $drawing->setWorksheet($sheet);
        } catch (\Throwable) {
            $sheet->setCellValue("F{$row}", 'تعذّر عرض الصورة');
        }
    }

    private function writeInstructionsSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('تعليمات');
        $this->styleSheet($sheet);
        $sheet->getColumnDimension('A')->setWidth(110);
        $lines = [
            'كيف ترفع المنتجات دفعة واحدة',
            '1. ورقة «التصنيفات» فيها رقم كل تصنيف وصورته. انسخ الرقم إلى عمود «رقم التصنيف» في ورقة المنتجات.',
            '2. اترك «رقم المنتج» فارغاً لإضافة منتج جديد. إذا كان الرقم موجوداً يتم تحديث ذلك المنتج.',
            '3. الخانة الفارغة في منتج موجود لا تغيّر القيمة الحالية. اكتب - لمسح الملاحظة أو الدرجة أو الاستخدام أو الوصف أو الأبعاد.',
            '4. المطلوب لمنتج جديد: رقم التصنيف، الاسم عربي، النقاط لكل وحدة، ونوع التحويل (نسبة أو ثابت).',
            '5. مع «نسبة» املأ نسبة النقاط وسعر الوحدة بالدينار. مع «ثابت» املأ قيمة النقطة.',
            '6. عمود الصورة: مسار ملف موجود على السيرفر مثل products/main/pipe.jpg. ارفع الصور إلى مجلد المنتجات أولاً، أو اترك الخانة فارغة.',
            '7. «اسم التصنيف (للعرض)» للمعرفة فقط ولا يُستورد. الاعتماد على رقم التصنيف.',
            '8. بعد التعبئة ارفع الملف من صفحة المنتجات → شيت المنتجات → رفع الشيت.',
        ];

        foreach ($lines as $index => $line) {
            $row = $index + 1;
            $sheet->setCellValue("A{$row}", $line);
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight($index === 0 ? 28 : 22);
        }

        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FF1A56DB'], 'name' => 'Arial'],
        ]);
    }

    private function applyCategoryDropdown(Worksheet $sheet, int $lastCategoryRow): void
    {
        if ($lastCategoryRow < 2) {
            return;
        }

        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
        $validation->setShowErrorMessage(true);
        $validation->setErrorTitle('رقم التصنيف');
        $validation->setError('اختر رقماً من ورقة التصنيفات.');
        $validation->setFormula1("='".self::CATEGORIES_SHEET."'!\$A\$2:\$A\${$lastCategoryRow}");
        $sheet->setDataValidation('B2:B2000', $validation);
    }

    private function styleSheet(Worksheet $sheet): void
    {
        $sheet->setRightToLeft(true);
        $sheet->getParent()?->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function importRow(array $values, array &$errors, array &$warnings): ?string
    {
        $id = $this->intValue($values['id'] ?? '');
        $isNew = $id === null;
        $product = null;

        if (! $isNew) {
            $product = Product::query()->find($id);
            if (! $product) {
                $errors[] = "رقم المنتج {$id} غير موجود. اترك الخانة فارغة لإضافة منتج جديد.";

                return null;
            }
        }

        $categoryId = $this->intValue($values['category_id'] ?? '');
        if ($categoryId !== null && ! ProductCategory::query()->whereKey($categoryId)->exists()) {
            $errors[] = "رقم التصنيف {$categoryId} غير موجود في ورقة التصنيفات.";

            return null;
        }
        if ($isNew && $categoryId === null) {
            $errors[] = 'رقم التصنيف مطلوب للمنتج الجديد.';

            return null;
        }

        $nameAr = $this->textValue($values['name_ar'] ?? '');
        $nameEn = $this->textValue($values['name_en'] ?? '');
        if ($isNew && ($nameAr === null || $nameAr === '')) {
            $errors[] = 'الاسم عربي مطلوب للمنتج الجديد.';

            return null;
        }
        if ($nameAr === '') {
            $errors[] = 'لا يمكن مسح اسم المنتج.';

            return null;
        }
        if ($nameEn === '') {
            $errors[] = 'لا يمكن مسح الاسم الإنجليزي.';

            return null;
        }

        $payload = [];
        if ($categoryId !== null) {
            $payload['product_category_id'] = $categoryId;
        }

        $this->applyOptionalText($payload, 'classification', $values['classification'] ?? '', 50, $errors, 'الدرجة');
        $this->applyOptionalText($payload, 'notes', $values['notes'] ?? '', 5000, $errors, 'الملاحظات');
        $this->applyOptionalNumber($payload, 'length_m', $values['length_m'] ?? '', $errors, 'الطول');
        $this->applyOptionalNumber($payload, 'thickness_mm', $values['thickness_mm'] ?? '', $errors, 'السمك');
        $this->applyOptionalNumber($payload, 'volume_ml', $values['volume_ml'] ?? '', $errors, 'الحجم');

        $points = $this->optionalNumber($values['points_per_unit'] ?? '', $errors, 'النقاط لكل وحدة');
        $type = $this->pointType($values['point_value_type'] ?? '', $errors);
        $percent = $this->optionalNumber($values['point_value_percent'] ?? '', $errors, 'نسبة النقاط');
        $fixed = $this->optionalNumber($values['point_value_fixed'] ?? '', $errors, 'قيمة النقطة');
        $price = $this->optionalNumber($values['reference_unit_price'] ?? '', $errors, 'سعر الوحدة');

        if ($errors !== []) {
            return null;
        }

        $image = $this->resolveImage($values['image'] ?? '', $warnings);

        if ($isNew) {
            if ($points === null || $points <= 0) {
                $errors[] = 'النقاط لكل وحدة مطلوبة ويجب أن تكون أكبر من صفر.';

                return null;
            }
            if ($type === null) {
                $errors[] = 'نوع التحويل مطلوب: نسبة أو ثابت.';

                return null;
            }
        }

        $resolvedType = $type ?? $product?->point_value_type;
        if ($resolvedType === 'percent') {
            $resolvedPercent = $percent ?? ($isNew ? null : $product?->point_value_percent);
            $resolvedPrice = $price ?? ($isNew ? null : ($product?->reference_unit_price_cents ? $product->reference_unit_price_cents / 100 : null));
            if ($type !== null || $percent !== null || $price !== null || $isNew) {
                if ($resolvedPercent === null || $resolvedPercent <= 0) {
                    $errors[] = 'نسبة النقاط مطلوبة عندما يكون التحويل نسبة.';
                }
                if ($resolvedPrice === null || $resolvedPrice <= 0) {
                    $errors[] = 'سعر الوحدة مطلوب عندما يكون التحويل نسبة.';
                }
            }
        } elseif ($resolvedType === 'fixed') {
            $resolvedFixed = $fixed ?? ($isNew ? null : $product?->point_value_fixed);
            if (($type !== null || $fixed !== null || $isNew) && ($resolvedFixed === null || $resolvedFixed <= 0)) {
                $errors[] = 'قيمة النقطة مطلوبة عندما يكون التحويل ثابتاً.';
            }
        }

        if ($errors !== []) {
            return null;
        }

        if ($points !== null) {
            $payload['points_per_unit'] = $points;
            if ($points <= 0) {
                $warnings[] = 'النقاط صفر، ولن يظهر المنتج في نقاط البيع.';
            }
        } elseif ($isNew) {
            $payload['points_per_unit'] = 0;
        }

        if ($type !== null) {
            $payload['point_value_type'] = $type;
            if ($type === 'percent') {
                $payload['point_value_fixed'] = null;
            } else {
                $payload['point_value_percent'] = null;
                $payload['reference_unit_price_cents'] = null;
            }
        }
        if ($percent !== null && ($payload['point_value_type'] ?? $product?->point_value_type) === 'percent') {
            $payload['point_value_percent'] = $percent;
        }
        if ($fixed !== null && ($payload['point_value_type'] ?? $product?->point_value_type) === 'fixed') {
            $payload['point_value_fixed'] = $fixed;
        }
        if ($price !== null && ($payload['point_value_type'] ?? $product?->point_value_type) === 'percent') {
            $payload['reference_unit_price_cents'] = (int) round($price * 100);
        }
        if ($image !== null) {
            $payload['main_image'] = $image;
        }

        DB::transaction(function () use (&$product, $isNew, $payload, $nameAr, $nameEn, $values) {
            if ($isNew) {
                $product = new Product;
            }

            $product->fill($payload);
            $product->save();

            if ($nameAr !== null) {
                $product->translateOrNew('ar')->name = $nameAr;
            } elseif ($isNew) {
                $product->translateOrNew('ar')->name = '';
            }

            if ($nameEn !== null) {
                $product->translateOrNew('en')->name = $nameEn;
            } elseif ($isNew) {
                $product->translateOrNew('en')->name = $nameAr ?: '';
            }

            $this->applyTranslation($product, 'ar', 'usage', $values['usage_ar'] ?? '');
            $this->applyTranslation($product, 'en', 'usage', $values['usage_en'] ?? '');
            $this->applyTranslation($product, 'ar', 'description', $values['description_ar'] ?? '');
            $this->applyTranslation($product, 'en', 'description', $values['description_en'] ?? '');
            $product->save();
        });

        return $isNew ? 'created' : 'updated';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $errors
     */
    private function applyOptionalText(array &$payload, string $column, string $raw, int $max, array &$errors, string $label): void
    {
        $value = $this->textValue($raw);
        if ($value === null) {
            return;
        }
        if (mb_strlen($value) > $max) {
            $errors[] = "{$label} أطول من المسموح ({$max}).";

            return;
        }
        $payload[$column] = $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $errors
     */
    private function applyOptionalNumber(array &$payload, string $column, string $raw, array &$errors, string $label): void
    {
        $value = $this->optionalNumber($raw, $errors, $label);
        if ($value === null && trim($raw) !== '' && trim($raw) !== '-' && trim($raw) !== '—') {
            return;
        }
        if (trim($raw) === '') {
            return;
        }
        $payload[$column] = $value;
    }

    private function applyTranslation(Product $product, string $locale, string $attribute, string $raw): void
    {
        $value = $this->textValue($raw);
        if ($value === null) {
            return;
        }

        $translation = $product->translateOrNew($locale);
        if (blank($translation->name)) {
            $translation->name = $product->translate('ar')?->name
                ?: $product->translate('en')?->name
                ?: ('منتج #'.$product->id);
        }
        $translation->{$attribute} = $value === '' ? null : $value;
    }

    /**
     * @return array{0: Worksheet, 1: int, 2: array<int, string>}|null
     */
    private function findProductsSheet(Spreadsheet $book): ?array
    {
        foreach ($book->getWorksheetIterator() as $sheet) {
            $highest = min($sheet->getHighestDataRow(), 8);
            $maxCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn() ?: 'A'), 40);
            for ($row = 1; $row <= $highest; $row++) {
                $columns = [];
                for ($col = 1; $col <= $maxCol; $col++) {
                    $label = $this->normalizeHeader($this->cellString($sheet->getCell([$col, $row])->getValue()));
                    $key = $this->headerKey($label);
                    if ($key) {
                        $columns[$col] = $key;
                    }
                }
                if (in_array('category_id', $columns, true) && in_array('name_ar', $columns, true)) {
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
            if (in_array($key, self::READONLY, true)) {
                continue;
            }
            $values[$key] = $this->cellString($sheet->getCell([$col, $row])->getValue());
        }

        return $values;
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
        if ($label === '') {
            return null;
        }

        foreach (self::COLUMNS as $key => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->normalizeHeader($alias) === $label) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function cellString(mixed $value): string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    /** null = اترك القيمة، '' = امسحها */
    private function textValue(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if ($raw === '-' || $raw === '—') {
            return '';
        }

        return $raw;
    }

    /**
     * @param  list<string>  $errors
     */
    private function optionalNumber(string $raw, array &$errors, string $label): ?float
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-' || $raw === '—') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $raw);
        if (! is_numeric($normalized)) {
            $errors[] = "{$label} يجب أن يكون رقماً.";

            return null;
        }

        return (float) $normalized;
    }

    private function intValue(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $number = (int) $raw;

        return $number > 0 ? $number : null;
    }

    /**
     * @param  list<string>  $errors
     */
    private function pointType(string $raw, array &$errors): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $normalized = mb_strtolower($raw);
        if (in_array($normalized, ['percent', 'نسبة', 'percentage'], true) || str_contains($raw, 'نسبة')) {
            return 'percent';
        }
        if (in_array($normalized, ['fixed', 'ثابت'], true) || str_contains($raw, 'ثابت')) {
            return 'fixed';
        }

        $errors[] = 'نوع التحويل يجب أن يكون «نسبة» أو «ثابت».';

        return null;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function resolveImage(string $raw, array &$warnings): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-' || $raw === '—') {
            return null;
        }

        $path = $raw;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $urlPath = parse_url($path, PHP_URL_PATH) ?: '';
            $path = preg_replace('#^/storage/#', '', ltrim($urlPath, '/')) ?: '';
        }

        $path = ltrim(preg_replace('#^storage/#', '', ltrim($path, '/')) ?? '', '/');
        $candidates = array_values(array_filter([
            $path,
            ! str_contains($path, '/') ? 'products/main/'.$path : null,
        ]));

        foreach ($candidates as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                return $candidate;
            }
        }

        $warnings[] = "الصورة غير موجودة على السيرفر ({$raw}). ارفعها إلى products/main ثم أعد الاستيراد، أو اترك الخانة فارغة.";

        return null;
    }

    private function categoryLabel(ProductCategory $category): string
    {
        $name = localized_name($category, 'name', $category->slug);
        $parent = $category->parent ? localized_name($category->parent, 'name').' ← ' : '';

        return '#'.$category->id.' '.$parent.$name;
    }

    private function plainText(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n", $text) ?? $text;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }
}

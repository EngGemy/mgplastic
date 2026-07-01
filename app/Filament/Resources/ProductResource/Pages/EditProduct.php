<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $record */
        $record = $this->record;

        // الترجمات
        $data['name_en']        = optional($record->translate('en'))->name;
        $data['description_en'] = optional($record->translate('en'))->description;
        $data['usage_en']       = optional($record->translate('en'))->usage;

        $data['name_ar']        = optional($record->translate('ar'))->name;
        $data['description_ar'] = optional($record->translate('ar'))->description;
        $data['usage_ar']       = optional($record->translate('ar'))->usage;

        if ($record->reference_unit_price_cents) {
            $data['reference_unit_price_dinars'] = number_format($record->reference_unit_price_cents / 100, 2, '.', '');
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = ProductResource::mergeProductPointsIntoData($data);

        // الأعمدة الأساسية
        $base = [
            'product_category_id' => $data['product_category_id'] ?? null,
            'product_standard_id' => $data['product_standard_id'] ?? null,
            'length_m'            => $data['length_m'] ?? null,
            'thickness_mm'        => $data['thickness_mm'] ?? null,
            'volume_ml'           => $data['volume_ml'] ?? null,
            'classification'      => $data['classification'] ?? null,
            'notes'               => $data['notes'] ?? null,
            'points_per_unit'     => $data['points_per_unit'] ?? 0,
            'point_value_type'      => $data['point_value_type'] ?? null,
            'point_value_percent'   => $data['point_value_percent'] ?? null,
            'point_value_fixed'     => $data['point_value_fixed'] ?? null,
            'reference_unit_price_cents' => $data['reference_unit_price_cents'] ?? null,
            'main_image'          => $this->normalizeUploadPath($data['main_image'] ?? null),
            'meta'                => $data['meta'] ?? null,
        ];

        // ✅ حقول الكتالوج (لو تم تحديثها)
        $catalogImagePath = $this->normalizeUploadPath($data['catalog_image_path'] ?? ($this->record->catalog_image_path ?? null));
        $catalogPdfPath   = $this->normalizeUploadPath($data['catalog_pdf_path'] ?? ($this->record->catalog_pdf_path ?? null));

        $catalog = [
            'catalog_image_path' => $catalogImagePath,
            // لو حقل تغيّر، نحدّث mime/size؛ لو لا، نخلي القديم
            'catalog_image_mime' => $catalogImagePath !== $this->record->catalog_image_path
                ? $this->guessMime($catalogImagePath)
                : $this->record->catalog_image_mime,
            'catalog_image_size' => $catalogImagePath !== $this->record->catalog_image_path
                ? $this->guessSize($catalogImagePath)
                : $this->record->catalog_image_size,

            'catalog_pdf_path'   => $catalogPdfPath,
            'catalog_pdf_mime'   => $catalogPdfPath !== $this->record->catalog_pdf_path
                ? $this->guessMime($catalogPdfPath)
                : $this->record->catalog_pdf_mime,
            'catalog_pdf_size'   => $catalogPdfPath !== $this->record->catalog_pdf_path
                ? $this->guessSize($catalogPdfPath)
                : $this->record->catalog_pdf_size,
        ];

        return array_merge($base, $catalog);
    }

    protected function afterSave(): void
    {
        /** @var Product $record */
        $record = $this->record;
        $data   = $this->form->getState();

        $record->translateOrNew('en')->name        = $data['name_en'] ?? '';
        $record->translateOrNew('en')->description = $data['description_en'] ?? '';
        $record->translateOrNew('en')->usage       = $data['usage_en'] ?? '';

        $record->translateOrNew('ar')->name        = $data['name_ar'] ?? '';
        $record->translateOrNew('ar')->description = $data['description_ar'] ?? '';
        $record->translateOrNew('ar')->usage       = $data['usage_ar'] ?? '';

        $record->save();
    }

    private function normalizeUploadPath($value): ?string
    {
        if (is_array($value)) {
            $value = $value['path'] ?? $value['url'] ?? null;
        }
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value !== '' ? ltrim($value, '/') : null;
    }

    private function guessMime(?string $path): ?string
    {
        if (!$path) return null;
        try {
            return Storage::disk('public')->mimeType($path) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function guessSize(?string $path): ?int
    {
        if (!$path) return null;
        try {
            return Storage::disk('public')->size($path) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

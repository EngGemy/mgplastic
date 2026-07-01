<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected static ?string $title = 'إضافة منتج جديد';

    public function form(Form $form): Form
    {
        return ProductResource::createWizardForm($form);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = ProductResource::mergeProductPointsIntoData($data);

        $base = [
            'product_category_id' => $data['product_category_id'] ?? null,
            'product_standard_id' => $data['product_standard_id'] ?? null,
            'length_m' => $data['length_m'] ?? null,
            'thickness_mm' => $data['thickness_mm'] ?? null,
            'volume_ml' => $data['volume_ml'] ?? null,
            'classification' => $data['classification'] ?? null,
            'notes' => $data['notes'] ?? null,
            'points_per_unit' => $data['points_per_unit'] ?? 0,
            'point_value_type' => $data['point_value_type'] ?? null,
            'point_value_percent' => $data['point_value_percent'] ?? null,
            'point_value_fixed' => $data['point_value_fixed'] ?? null,
            'reference_unit_price_cents' => $data['reference_unit_price_cents'] ?? null,
            'main_image' => $this->normalizeUploadPath($data['main_image'] ?? null),
            'meta' => $data['meta'] ?? null,
        ];

        $catalogImagePath = $this->normalizeUploadPath($data['catalog_image_path'] ?? null);
        $catalogPdfPath = $this->normalizeUploadPath($data['catalog_pdf_path'] ?? null);

        $catalog = [
            'catalog_image_path' => $catalogImagePath,
            'catalog_image_mime' => $this->guessMime($catalogImagePath),
            'catalog_image_size' => $this->guessSize($catalogImagePath),
            'catalog_pdf_path' => $catalogPdfPath,
            'catalog_pdf_mime' => $this->guessMime($catalogPdfPath),
            'catalog_pdf_size' => $this->guessSize($catalogPdfPath),
        ];

        return array_merge($base, $catalog);
    }

    protected function afterCreate(): void
    {
        /** @var Product $record */
        $record = $this->record;
        $data = $this->form->getState();

        $record->translateOrNew('en')->name = $data['name_en'] ?? '';
        $record->translateOrNew('en')->description = $data['description_en'] ?? '';
        $record->translateOrNew('en')->usage = $data['usage_en'] ?? '';

        $record->translateOrNew('ar')->name = $data['name_ar'] ?? '';
        $record->translateOrNew('ar')->description = $data['description_ar'] ?? '';
        $record->translateOrNew('ar')->usage = $data['usage_ar'] ?? '';

        $record->save();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم إضافة المنتج بنجاح';
    }

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()->label('حفظ المنتج');
    }

    private function normalizeUploadPath($value): ?string
    {
        if (is_array($value)) {
            $value = $value['path'] ?? $value['url'] ?? null;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? ltrim($value, '/') : null;
    }

    private function guessMime(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        try {
            return Storage::disk('public')->mimeType($path) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function guessSize(?string $path): ?int
    {
        if (! $path) {
            return null;
        }
        try {
            return Storage::disk('public')->size($path) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Pages\ProductPointsChangesReport;
use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductTag;
use App\Services\ProductPointsRateService;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected ?float $pointsRateBeforeSave = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $record */
        $record = $this->record;

        // الترجمات
        $data['name_en'] = optional($record->translate('en'))->name;
        $data['description_en'] = optional($record->translate('en'))->description;
        $data['usage_en'] = optional($record->translate('en'))->usage;

        $data['name_ar'] = optional($record->translate('ar'))->name;
        $data['description_ar'] = optional($record->translate('ar'))->description;
        $data['usage_ar'] = optional($record->translate('ar'))->usage;
        $data['tag_names'] = $record->tags()->orderBy('name')->pluck('name')->all();

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
            'pieces_per_box' => $data['pieces_per_box'] ?? null,
            'piece_price_lyd' => $data['piece_price_lyd'] ?? null,
            'piece_price_aed' => $data['piece_price_aed'] ?? null,
            'box_price_lyd' => $data['box_price_lyd'] ?? null,
            'box_price_aed' => $data['box_price_aed'] ?? null,
            'main_image' => $this->normalizeUploadPath($data['main_image'] ?? null),
            'meta' => $data['meta'] ?? null,
        ];

        // ✅ حقول الكتالوج (لو تم تحديثها)
        $catalogImagePath = $this->normalizeUploadPath($data['catalog_image_path'] ?? ($this->record->catalog_image_path ?? null));
        $catalogPdfPath = $this->normalizeUploadPath($data['catalog_pdf_path'] ?? ($this->record->catalog_pdf_path ?? null));

        $catalog = [
            'catalog_image_path' => $catalogImagePath,
            // لو حقل تغيّر، نحدّث mime/size؛ لو لا، نخلي القديم
            'catalog_image_mime' => $catalogImagePath !== $this->record->catalog_image_path
                ? $this->guessMime($catalogImagePath)
                : $this->record->catalog_image_mime,
            'catalog_image_size' => $catalogImagePath !== $this->record->catalog_image_path
                ? $this->guessSize($catalogImagePath)
                : $this->record->catalog_image_size,

            'catalog_pdf_path' => $catalogPdfPath,
            'catalog_pdf_mime' => $catalogPdfPath !== $this->record->catalog_pdf_path
                ? $this->guessMime($catalogPdfPath)
                : $this->record->catalog_pdf_mime,
            'catalog_pdf_size' => $catalogPdfPath !== $this->record->catalog_pdf_path
                ? $this->guessSize($catalogPdfPath)
                : $this->record->catalog_pdf_size,
        ];

        return array_merge($base, $catalog);
    }

    protected function beforeSave(): void
    {
        /** @var Product $record */
        $record = $this->record;
        $this->pointsRateBeforeSave = round((float) ($record->points_per_unit ?? 0), 4);
        // نمنع الـ Observer من التسجيل المزدوج — نسجّل نحن بعد الحفظ مع إشعار الواجهة
        $record->pointsRateAudited = true;
    }

    protected function afterSave(): void
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

        $record->pointsRateAudited = true;
        $record->save();
        ProductTag::syncNamesOntoProduct($record, $data['tag_names'] ?? []);

        $this->auditPointsRateIfChanged();
    }

    /**
     * أي تغيير لسعر النقاط من صفحة المنتج → سجل + تنبيه إدارة + رسالة للمُعدِّل.
     */
    protected function auditPointsRateIfChanged(): void
    {
        if ($this->pointsRateBeforeSave === null) {
            return;
        }

        /** @var Product $record */
        $record = $this->record->fresh() ?? $this->record;
        $old = $this->pointsRateBeforeSave;
        $new = round((float) ($record->points_per_unit ?? 0), 4);

        if (abs($new - $old) < 0.00005) {
            return;
        }

        if ($new <= 0) {
            return;
        }

        try {
            // Observer كان متخطّى — نسجّل هنا صراحةً
            $record->pointsRateAudited = false;
            $change = app(ProductPointsRateService::class)->auditPersistedChange(
                $record,
                $old,
                auth()->user(),
                note: 'تغيير من تعديل المنتج',
                notifyAdmins: true,
            );
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('تم حفظ المنتج')
                ->body('لكن تعذّر تسجيل تغيير سعر النقاط في التقرير: '.$e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $change) {
            return;
        }

        $reportUrl = ProductPointsChangesReport::getUrl();

        Notification::make()
            ->title('تم تسجيل تغيير سعر النقاط')
            ->body(sprintf(
                "من %s → %s نقطة/وحدة (%s%s).\nمخزون جملة: %s · قطاعي: %s · تقدير الفرق: %+d نقطة.\nظهر في تقرير تغيرات الأسعار وتم تنبيه الإدارة.",
                number_format((float) $change->old_points_per_unit, 2),
                number_format((float) $change->new_points_per_unit, 2),
                (float) $change->delta_points_per_unit >= 0 ? '+' : '',
                $change->change_percent !== null
                    ? number_format((float) $change->change_percent, 1).'%'
                    : number_format((float) $change->delta_points_per_unit, 2),
                number_format((int) $change->wholesale_remaining_qty),
                number_format((int) $change->retail_remaining_qty),
                (int) $change->estimated_points_delta,
            ))
            ->warning()
            ->persistent()
            ->actions([
                NotificationAction::make('open_report')
                    ->label('فتح تقرير تغيرات الأسعار')
                    ->url($reportUrl),
            ])
            ->send();
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

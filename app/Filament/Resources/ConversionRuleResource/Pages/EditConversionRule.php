<?php

namespace App\Filament\Resources\ConversionRuleResource\Pages;

use App\Filament\Resources\ConversionRuleResource;
use Filament\Resources\Pages\EditRecord;

class EditConversionRule extends EditRecord
{
    protected static string $resource = ConversionRuleResource::class;

    protected static string $view = 'filament.resources.conversion-rules.edit';

    public function getTitle(): string
    {
        return 'إعدادات صرف النقاط';
    }

    public function getHeading(): string
    {
        return 'إعدادات صرف النقاط';
    }

    public function getSubheading(): ?string
    {
        return 'نافذة الصرف والحدود فقط — قيمة النقاط تُضبط من المنتجات.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $cents = (int) ($data['fee_fixed_cents'] ?? 0);
        $data['fee_fixed_dinars'] = $cents > 0
            ? number_format($cents / 100, 2, '.', '')
            : '0';

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['currency'] = 'LYD';
        $data['vendor_store_id'] = null;
        $data['name'] = $data['name'] ?? 'إعدادات صرف النقاط';

        if (array_key_exists('fee_fixed_dinars', $data)) {
            $data['fee_fixed_cents'] = (int) round(((float) $data['fee_fixed_dinars']) * 100);
            unset($data['fee_fixed_dinars']);
        }

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم حفظ إعدادات صرف النقاط';
    }
}

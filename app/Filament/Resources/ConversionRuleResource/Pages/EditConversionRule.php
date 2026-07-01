<?php

namespace App\Filament\Resources\ConversionRuleResource\Pages;

use App\Filament\Resources\ConversionRuleResource;
use Filament\Resources\Pages\EditRecord;

class EditConversionRule extends EditRecord
{
    protected static string $resource = ConversionRuleResource::class;

    public function getTitle(): string
    {
        return 'إعدادات صرف النقاط';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['currency'] = 'LYD';
        $data['vendor_store_id'] = null;

        if (isset($data['fee_fixed_dinars'])) {
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

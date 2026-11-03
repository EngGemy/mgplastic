<?php

namespace App\Filament\Resources\OtaUpdateResource\Pages;

use App\Filament\Resources\OtaUpdateResource;
use App\Services\SecurityAuditService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOtaUpdate extends EditRecord
{
    protected static string $resource = OtaUpdateResource::class;

    public function getTitle(): string
    {
        return 'تعديل تحديث OTA';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('حذف'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = OtaUpdateResource::assertVersionIsNext($data, $this->record);

        $wasActive = (bool) $this->record->is_active;
        $willBeActive = (bool) ($data['is_active'] ?? false);

        if (! $wasActive && $willBeActive) {
            $data['activated_by'] = auth()->id();
            $data['activated_at'] = now();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        app(SecurityAuditService::class)->record(
            'ota.updated',
            auth()->user(),
            $this->record,
            [
                'platform' => $this->record->platform,
                'native_build' => $this->record->native_build,
                'version' => $this->record->version,
                'is_active' => $this->record->is_active,
            ]
        );

        OtaUpdateResource::forgetOtaCache();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم حفظ التعديلات';
    }
}

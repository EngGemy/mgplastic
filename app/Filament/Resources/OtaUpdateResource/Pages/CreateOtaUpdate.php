<?php

namespace App\Filament\Resources\OtaUpdateResource\Pages;

use App\Filament\Resources\OtaUpdateResource;
use App\Services\SecurityAuditService;
use Filament\Resources\Pages\CreateRecord;

class CreateOtaUpdate extends CreateRecord
{
    protected static string $resource = OtaUpdateResource::class;

    public function getTitle(): string
    {
        return 'رفع تحديث OTA';
    }

    public function getHeading(): string
    {
        return 'رفع تحديث OTA';
    }

    public function getSubheading(): ?string
    {
        return 'استخدم قيم platform و native_build و version المطبوعة من أمر yarn ota:release.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = OtaUpdateResource::assertVersionIsNext($data);
        $data['uploaded_by'] = auth()->id();
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        if ($data['is_active']) {
            $data['activated_by'] = auth()->id();
            $data['activated_at'] = now();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        app(SecurityAuditService::class)->record(
            'ota.uploaded',
            auth()->user(),
            $this->record,
            [
                'platform' => $this->record->platform,
                'native_build' => $this->record->native_build,
                'version' => $this->record->version,
                'is_active' => $this->record->is_active,
            ]
        );

        if ($this->record->is_active) {
            app(SecurityAuditService::class)->record(
                'ota.activated',
                auth()->user(),
                $this->record,
                [
                    'platform' => $this->record->platform,
                    'native_build' => $this->record->native_build,
                    'version' => $this->record->version,
                ]
            );
        }

        OtaUpdateResource::forgetOtaCache();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم رفع التحديث';
    }
}

<?php

namespace App\Filament\Resources\HomePopupResource\Pages;

use App\Filament\Resources\HomePopupResource;
use App\Models\HomePopup;
use Filament\Resources\Pages\CreateRecord;

class CreateHomePopup extends CreateRecord
{
    protected static string $resource = HomePopupResource::class;

    public function getTitle(): string
    {
        return 'بوب أب جديد';
    }

    public function getHeading(): string
    {
        return 'بوب أب جديد على الرئيسية';
    }

    public function getSubheading(): ?string
    {
        return 'يظهر جوه home/layout كـ data.popup — صورة + أزرار + جمهور. وقّفه في أي وقت عشان يختفي فوراً.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['version'] = 1;
        $data['show'] = $data['show'] ?? 'once';
        $data['kind'] = $data['kind'] ?? 'announcement';
        unset($data['rebroadcast']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var HomePopup $record */
        $record = $this->getRecord();
        $record->refreshImageRatioFromFile();
        if ($record->isDirty('image_ratio')) {
            $record->saveQuietly();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم إنشاء البوب أب';
    }
}

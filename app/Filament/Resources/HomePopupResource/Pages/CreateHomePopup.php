<?php

namespace App\Filament\Resources\HomePopupResource\Pages;

use App\Filament\Resources\HomePopupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHomePopup extends CreateRecord
{
    protected static string $resource = HomePopupResource::class;

    public function getTitle(): string
    {
        return 'إضافة إعلان منبثق';
    }

    public function getHeading(): string
    {
        return 'إضافة إعلان منبثق';
    }

    public function getSubheading(): ?string
    {
        return 'يظهر فوق الرئيسية عند فتح التطبيق — صورة + زر يودّي لوجهة حسب الدور.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم حفظ الإعلان';
    }
}

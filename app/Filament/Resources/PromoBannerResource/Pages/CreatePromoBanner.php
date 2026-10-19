<?php

namespace App\Filament\Resources\PromoBannerResource\Pages;

use App\Filament\Resources\PromoBannerResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromoBanner extends CreateRecord
{
    protected static string $resource = PromoBannerResource::class;

    public function getTitle(): string
    {
        return 'إضافة بانر دعائي';
    }

    public function getHeading(): string
    {
        return 'إضافة بانر دعائي';
    }

    public function getSubheading(): ?string
    {
        return 'يظهر في التطبيق بين السباكين والمتاجر — ويمكنه فتح مجموعة أو إعلان أو رابط.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم حفظ البانر';
    }
}

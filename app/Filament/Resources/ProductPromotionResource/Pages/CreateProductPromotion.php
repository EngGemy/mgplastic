<?php

namespace App\Filament\Resources\ProductPromotionResource\Pages;

use App\Filament\Resources\ProductPromotionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductPromotion extends CreateRecord
{
    protected static string $resource = ProductPromotionResource::class;

    public function getTitle(): string
    {
        return 'إضافة إعلان دعاية';
    }

    public function getHeading(): string
    {
        return 'إضافة إعلان دعاية لمنتجات MG';
    }

    public function getSubheading(): ?string
    {
        return 'صورة أو فيديو أو مقارنة — يظهر في سكشن دعاية المنتجات بالتطبيق.';
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

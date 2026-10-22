<?php

namespace App\Filament\Resources\WarrantyResource\Pages;

use App\Filament\Resources\WarrantyResource;
use Filament\Resources\Pages\ListRecords;

class ListWarranties extends ListRecords
{
    protected static string $resource = WarrantyResource::class;

    protected static string $view = 'filament.resources.warranties.list';

    public function getTitle(): string
    {
        return 'الضمانات الصادرة';
    }

    public function getHeading(): string
    {
        return 'الضمانات الصادرة';
    }

    public function getSubheading(): ?string
    {
        return 'سجل تشغيلي — الإصدار من تطبيق السباك بعد اجتياز عتبات السمعة.';
    }
}

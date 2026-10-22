<?php

namespace App\Filament\Resources\WarrantyClaimResource\Pages;

use App\Filament\Resources\WarrantyClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListWarrantyClaims extends ListRecords
{
    protected static string $resource = WarrantyClaimResource::class;

    protected static string $view = 'filament.resources.warranty-claims.list';

    public function getTitle(): string
    {
        return 'مطالبات الضمان';
    }

    public function getHeading(): string
    {
        return 'مطالبات الضمان';
    }

    public function getSubheading(): ?string
    {
        return 'طابور معالجة — المطالبات تُفتح من التطبيق مرتبطة بكود ضمان.';
    }
}

<?php

namespace App\Filament\Resources\OcrTermAliasResource\Pages;

use App\Filament\Resources\OcrTermAliasResource;
use Filament\Resources\Pages\ListRecords;

class ListOcrTermAliases extends ListRecords
{
    protected static string $resource = OcrTermAliasResource::class;

    public function getTitle(): string
    {
        return 'كلمات OCR الجديدة';
    }

    public function getSubheading(): ?string
    {
        return 'اعتماد أو رفض مرادفات تعلّمها النظام من تصحيحات المستخدمين. المعلّقة تقترح فقط ولا تُقبل تلقائياً.';
    }
}

<?php

namespace App\Filament\Resources\OcrTermAliasResource\Pages;

use App\Filament\Resources\OcrTermAliasResource;
use Filament\Actions;
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
        return 'أضف كلمة أو مرادفاً يدوياً واربطه بمسار العائلة، أو اعتمد/ارفض ما تعلّمه النظام من تصحيحات المستخدمين. المعلّقة تقترح فقط ولا تُقبل تلقائياً.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('إضافة كلمة')
                ->icon('heroicon-o-plus'),
        ];
    }
}

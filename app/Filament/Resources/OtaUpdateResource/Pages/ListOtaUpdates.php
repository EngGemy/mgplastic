<?php

namespace App\Filament\Resources\OtaUpdateResource\Pages;

use App\Filament\Resources\OtaUpdateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOtaUpdates extends ListRecords
{
    protected static string $resource = OtaUpdateResource::class;

    public function getTitle(): string
    {
        return 'تحديثات OTA';
    }

    public function getHeading(): string
    {
        return 'تحديثات التطبيق المباشرة (OTA)';
    }

    public function getSubheading(): ?string
    {
        return 'رفع وتفعيل ملفات JS/صور للتطبيق دون إصدار جديد على المتاجر. صلاحية حساسة.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('رفع تحديث')
                ->icon('heroicon-o-arrow-up-tray'),
        ];
    }
}

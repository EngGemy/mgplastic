<?php

namespace App\Filament\Resources\SystemLabelResource\Pages;

use App\Filament\Resources\SystemLabelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSystemLabel extends EditRecord
{
    protected static string $resource = SystemLabelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

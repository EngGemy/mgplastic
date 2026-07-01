<?php

namespace App\Filament\Resources\SizeSystemResource\Pages;

use App\Filament\Resources\SizeSystemResource;
use Filament\Resources\Pages\EditRecord;

class EditSizeSystem extends EditRecord
{
    protected static string $resource = SizeSystemResource::class;
    protected ?string $heading = null;

    public function getHeading(): string
    {
        return __('Edit Size System');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

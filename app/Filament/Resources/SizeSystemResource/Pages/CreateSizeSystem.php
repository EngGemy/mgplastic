<?php

namespace App\Filament\Resources\SizeSystemResource\Pages;

use App\Filament\Resources\SizeSystemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSizeSystem extends CreateRecord
{
    protected static string $resource = SizeSystemResource::class;
    protected ?string $heading = null;

    public function getHeading(): string
    {
        return __('Add Size System');
    }
}

<?php

namespace App\Filament\Resources\SizeSystemResource\Pages;

use App\Filament\Resources\SizeSystemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSizeSystems extends ListRecords
{
    protected static string $resource = SizeSystemResource::class;

    public function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('Add System'))->slideOver()->modalWidth('3xl'),
        ];
    }
}

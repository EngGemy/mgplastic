<?php

namespace App\Filament\Resources\WebsiteServiceResource\Pages;

use App\Filament\Resources\WebsiteServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWebsiteServices extends ListRecords
{
    protected static string $resource = WebsiteServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

<?php

namespace App\Filament\Resources\WebsiteStatResource\Pages;

use App\Filament\Resources\WebsiteStatResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWebsiteStats extends ListRecords
{
    protected static string $resource = WebsiteStatResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}

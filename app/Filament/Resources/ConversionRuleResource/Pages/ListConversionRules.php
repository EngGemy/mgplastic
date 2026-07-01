<?php

namespace App\Filament\Resources\ConversionRuleResource\Pages;

use App\Filament\Resources\ConversionRuleResource;
use App\Models\ConversionRule;
use Filament\Resources\Pages\ListRecords;

class ListConversionRules extends ListRecords
{
    protected static string $resource = ConversionRuleResource::class;

    public function mount(): void
    {
        $settings = ConversionRule::globalSettings();

        $this->redirect(
            ConversionRuleResource::getUrl('edit', ['record' => $settings]),
            navigate: true
        );
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

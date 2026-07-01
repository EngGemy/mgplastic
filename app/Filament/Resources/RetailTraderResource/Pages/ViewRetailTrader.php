<?php

namespace App\Filament\Resources\RetailTraderResource\Pages;

use App\Filament\Resources\RetailTraderResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRetailTrader extends ViewRecord
{
    protected static string $resource = RetailTraderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load(['country', 'city', 'parentDistributor'])
            ->loadCount('plumbers');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('تعديل'),
        ];
    }
}

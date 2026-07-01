<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewStore extends ViewRecord
{
    protected static string $resource = StoreResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load(['country', 'city', 'parentDistributor'])
            ->loadCount('retailTraders');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('تعديل المتجر'),
            Actions\Action::make('open_map')
                ->label('OpenStreetMap')
                ->icon('heroicon-o-map')
                ->color('info')
                ->url(fn () => $this->record->mapUrl())
                ->openUrlInNewTab()
                ->visible(fn () => $this->record->hasMapLocation()),
        ];
    }
}

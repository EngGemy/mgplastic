<?php

namespace App\Filament\Resources\InvoiceDistributionResource\Pages;

use App\Filament\Resources\InvoiceDistributionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInvoiceDistribution extends EditRecord
{
    protected static string $resource = InvoiceDistributionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

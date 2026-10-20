<?php

namespace App\Filament\Resources\WarrantyClaimResource\Pages;

use App\Filament\Resources\WarrantyClaimResource;
use Filament\Resources\Pages\EditRecord;

class EditWarrantyClaim extends EditRecord
{
    protected static string $resource = WarrantyClaimResource::class;

    protected function afterSave(): void
    {
        $record = $this->record;
        if (in_array($record->status, ['resolved', 'rejected'], true) && ! $record->resolved_at) {
            $record->update([
                'resolved_at' => now(),
                'handled_by' => auth()->id(),
            ]);
        }
    }
}

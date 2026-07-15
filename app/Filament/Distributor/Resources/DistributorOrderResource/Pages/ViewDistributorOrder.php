<?php

namespace App\Filament\Distributor\Resources\DistributorOrderResource\Pages;

use App\Filament\Distributor\Resources\DistributorOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDistributorOrder extends ViewRecord
{
    protected static string $resource = DistributorOrderResource::class;

    public function getTitle(): string
    {
        return 'طلب رقم '.($this->record->order_number ?? $this->record->id);
    }
}

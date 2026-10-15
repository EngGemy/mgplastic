<?php

namespace App\Filament\Distributor\Resources\DistributorOrderResource\Pages;

use App\Filament\Concerns\ManagesOrderLifecycleActions;
use App\Filament\Distributor\Resources\DistributorOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDistributorOrder extends ViewRecord
{
    use ManagesOrderLifecycleActions;

    protected static string $resource = DistributorOrderResource::class;

    public function getTitle(): string
    {
        return 'طلب رقم '.($this->record->order_number ?? $this->record->id);
    }

    protected function getHeaderActions(): array
    {
        return $this->getOrderLifecycleHeaderActions();
    }
}

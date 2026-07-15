<?php

namespace App\Filament\Trader\Resources\TraderOrderResource\Pages;

use App\Filament\Trader\Resources\TraderOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTraderOrder extends ViewRecord
{
    protected static string $resource = TraderOrderResource::class;

    public function getTitle(): string
    {
        return 'طلب رقم '.($this->record->order_number ?? $this->record->id);
    }
}

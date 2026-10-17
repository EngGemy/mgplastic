<?php

namespace App\Filament\Warehouse\Resources\WarehouseWholesalerResource\Pages;

use App\Filament\Warehouse\Resources\WarehouseWholesalerResource;
use Filament\Resources\Pages\ListRecords;

class ListWarehouseWholesalers extends ListRecords
{
    protected static string $resource = WarehouseWholesalerResource::class;

    public function getTitle(): string
    {
        return 'مخازن الجملة في الشبكة';
    }

    public function getSubheading(): ?string
    {
        return 'كل موزّعي الجملة تحت مخزن الدعوة الرئيسي';
    }
}

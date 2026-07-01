<?php

namespace App\Filament\Resources\PointRuleResource\Pages;

use App\Filament\Pages\ProductsReport;
use App\Filament\Resources\PointRuleResource;
use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\ListRecords;

class ListPointRules extends ListRecords
{
    protected static string $resource = PointRuleResource::class;

    public function mount(): void
    {
        $this->redirect(ProductsReport::getUrl(), navigate: true);
    }
}

<?php

namespace App\Filament\Trader\Resources\TraderDistributionResource\Pages;

use App\Filament\Trader\Resources\TraderDistributionResource;
use App\Filament\Resources\InvoiceDistributionResource\Pages\CreateInvoiceDistribution;

class CreateTraderDistribution extends CreateInvoiceDistribution
{
    protected static string $resource = TraderDistributionResource::class;
}

<?php

namespace App\Filament\Trader\Resources\TraderPlumberResource\Pages;

use App\Filament\Trader\Resources\TraderPlumberResource;
use Filament\Resources\Pages\EditRecord;

class EditTraderPlumber extends EditRecord
{
    protected static string $resource = TraderPlumberResource::class;

    protected static ?string $title = 'تعديل سباك';
}

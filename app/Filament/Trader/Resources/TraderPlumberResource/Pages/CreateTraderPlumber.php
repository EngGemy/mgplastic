<?php

namespace App\Filament\Trader\Resources\TraderPlumberResource\Pages;

use App\Filament\Trader\Resources\TraderPlumberResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateTraderPlumber extends CreateRecord
{
    protected static string $resource = TraderPlumberResource::class;

    protected static ?string $title = 'إضافة سباك';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['role'] = User::ROLE_PLUMBER;
        $data['parent_distributor_id'] = auth()->id();
        $data['is_approved'] = true;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['is_independent'] = false;
        $data['is_phone_verified'] = true;

        return $data;
    }
}

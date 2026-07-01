<?php

namespace App\Filament\Distributor\Resources\DistributorMyStoreResource\Pages;

use App\Filament\Distributor\Resources\DistributorMyStoreResource;
use Filament\Resources\Pages\EditRecord;

class EditDistributorMyStore extends EditRecord
{
    protected static string $resource = DistributorMyStoreResource::class;

    protected static ?string $title = 'متجري والكتالوج';

    public function mount(int|string|null $record = null): void
    {
        $user = auth()->user();
        abort_unless($user?->isWholesaleDistributor(), 403);

        $this->record = $user;
        $this->authorizeAccess();
        $this->fillForm();
        $this->previousUrl = url()->previous();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم حفظ بيانات متجرك';
    }
}

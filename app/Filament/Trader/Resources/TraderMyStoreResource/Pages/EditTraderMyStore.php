<?php

namespace App\Filament\Trader\Resources\TraderMyStoreResource\Pages;

use App\Filament\Trader\Resources\TraderMyStoreResource;
use Filament\Resources\Pages\EditRecord;

class EditTraderMyStore extends EditRecord
{
    protected static string $resource = TraderMyStoreResource::class;

    protected static ?string $title = 'متجري والكتالوج';

    public function mount(int|string|null $record = null): void
    {
        $user = auth()->user();
        abort_unless($user?->isRetailTrader(), 403);

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

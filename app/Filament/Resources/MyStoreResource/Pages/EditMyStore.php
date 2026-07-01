<?php

namespace App\Filament\Resources\MyStoreResource\Pages;

use App\Filament\Resources\MyStoreResource;
use Filament\Resources\Pages\EditRecord;

class EditMyStore extends EditRecord
{
    protected static string $resource = MyStoreResource::class;

    protected static ?string $title = 'متجري والكتالوج';

    public function mount(int|string|null $record = null): void
    {
        $user = auth()->user();

        abort_unless(
            $user && MyStoreResource::canAccessNetworkStore($user),
            403
        );

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

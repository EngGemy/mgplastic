<?php

namespace App\Filament\Resources\DatabaseNotificationResource\Pages;

use App\Filament\Pages\SendNotification;
use App\Filament\Resources\DatabaseNotificationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDatabaseNotifications extends ListRecords
{
    protected static string $resource = DatabaseNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('compose')
                ->label('إرسال إشعار')
                ->icon('heroicon-o-paper-airplane')
                ->url(SendNotification::getUrl()),
        ];
    }
}

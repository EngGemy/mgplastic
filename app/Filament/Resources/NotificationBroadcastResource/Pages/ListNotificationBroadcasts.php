<?php

namespace App\Filament\Resources\NotificationBroadcastResource\Pages;

use App\Filament\Pages\SendNotification;
use App\Filament\Resources\NotificationBroadcastResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNotificationBroadcasts extends ListRecords
{
    protected static string $resource = NotificationBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('compose')
                ->label('إرسال إشعار جديد')
                ->icon('heroicon-o-paper-airplane')
                ->url(SendNotification::getUrl()),
        ];
    }
}

<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Services\CustomerWhatsAppNotifier;
use Filament\Tables;

class CustomerWhatsAppActions
{
    /**
     * Free welcome WhatsApp: opens wa.me with a ready message (staff taps Send).
     */
    public static function welcomeTableAction(string $name = 'whatsapp_welcome'): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)
            ->label('واتساب ترحيب')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('success')
            ->tooltip('فتح واتساب برسالة ترحيبية جاهزة — مجاني')
            ->visible(fn (User $record) => filled(trim((string) $record->phone))
                && app(CustomerWhatsAppNotifier::class)->welcomeChatUrl($record) !== null)
            ->url(
                fn (User $record) => app(CustomerWhatsAppNotifier::class)->welcomeChatUrl($record),
                shouldOpenInNewTab: true,
            );
    }
}

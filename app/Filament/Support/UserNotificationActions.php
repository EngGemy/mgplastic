<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Services\AdminNotificationService;
use App\Support\AdminPermissions;
use Filament\Forms;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Tables;

class UserNotificationActions
{
    /** @return array<int, Forms\Components\Component> */
    public static function formFields(): array
    {
        return [
            Forms\Components\TextInput::make('title')
                ->label('عنوان الإشعار')
                ->required()
                ->maxLength(255),

            Forms\Components\Textarea::make('body')
                ->label('محتوى الإشعار')
                ->rows(4)
                ->maxLength(2000),

            Forms\Components\Select::make('status')
                ->label('النوع')
                ->options([
                    'info' => 'معلومة',
                    'success' => 'نجاح',
                    'warning' => 'تحذير',
                    'danger' => 'تنبيه هام',
                ])
                ->default('info')
                ->native(false),

            Forms\Components\TextInput::make('url')
                ->label('رابط (اختياري)')
                ->url()
                ->maxLength(500),
        ];
    }

    public static function canSend(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(AdminPermissions::USERS_MANAGE);
    }

    public static function tableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('sendNotification')
            ->label('إرسال إشعار')
            ->icon('heroicon-o-bell-alert')
            ->color('info')
            ->visible(fn () => self::canSend())
            ->form(self::formFields())
            ->action(function (User $record, array $data): void {
                AdminNotificationService::send(
                    $record,
                    $data['title'],
                    $data['body'] ?? null,
                    $data['status'] ?? 'info',
                    $data['url'] ?? null,
                );

                FilamentNotification::make()
                    ->title('تم إرسال الإشعار')
                    ->body("وصل الإشعار إلى {$record->name}")
                    ->success()
                    ->send();
            });
    }

    public static function bulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('sendNotificationBulk')
            ->label('إرسال إشعار للمحددين')
            ->icon('heroicon-o-bell-alert')
            ->color('info')
            ->visible(fn () => self::canSend())
            ->form(self::formFields())
            ->action(function ($records, array $data): void {
                $count = AdminNotificationService::sendToMany(
                    $records,
                    $data['title'],
                    $data['body'] ?? null,
                    $data['status'] ?? 'info',
                    $data['url'] ?? null,
                );

                FilamentNotification::make()
                    ->title('تم إرسال الإشعارات')
                    ->body("تم الإرسال إلى {$count} مستخدم")
                    ->success()
                    ->send();
            });
    }
}

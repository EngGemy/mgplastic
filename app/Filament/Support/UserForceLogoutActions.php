<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Services\ForceLogoutService;
use App\Support\AdminPermissions;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Tables;

class UserForceLogoutActions
{
    public static function canRevoke(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            || $user?->canAdminPermission(AdminPermissions::USERS_MANAGE);
    }

    public static function tableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('forceLogout')
            ->label('إنهاء الجلسة')
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('warning')
            ->visible(fn () => self::canRevoke())
            ->requiresConfirmation()
            ->modalHeading('إنهاء جلسة المستخدم')
            ->modalDescription('سيتم تسجيل خروج المستخدم من الويب والجوال فورًا (جلسات + توكنات API + البصمة).')
            ->modalSubmitActionLabel('إنهاء الجلسة الآن')
            ->action(fn (User $record) => self::run($record));
    }

    public static function headerAction(): Actions\Action
    {
        return Actions\Action::make('forceLogout')
            ->label('إنهاء الجلسة / تسجيل خروج')
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('warning')
            ->visible(fn () => self::canRevoke())
            ->requiresConfirmation()
            ->modalHeading('إنهاء جلسة المستخدم')
            ->modalDescription('سيتم تسجيل خروج المستخدم من الويب والجوال فورًا (جلسات + توكنات API + البصمة).')
            ->modalSubmitActionLabel('إنهاء الجلسة الآن')
            ->action(function (\Livewire\Component $livewire): void {
                if (! method_exists($livewire, 'getRecord')) {
                    return;
                }

                $record = $livewire->getRecord();

                if ($record instanceof User) {
                    self::run($record);
                }
            });
    }

    public static function run(User $user): void
    {
        $result = app(ForceLogoutService::class)->revokeAll($user);

        Notification::make()
            ->success()
            ->title('تم إنهاء الجلسة')
            ->body(sprintf(
                '%s — جلسات ويب: %d · توكنات: %d · بصمة: %d',
                $user->name,
                $result['sessions'],
                $result['tokens'],
                $result['biometric'],
            ))
            ->send();
    }
}

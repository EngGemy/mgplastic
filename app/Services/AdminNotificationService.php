<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\SyncFilamentDatabaseNotification;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class AdminNotificationService
{
    public static function send(
        User $user,
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $url = null,
        ?string $actionLabel = null,
    ): void {
        $notification = Notification::make()
            ->title($title)
            ->body($body ?? '');

        match ($status) {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification->info(),
        };

        if ($url) {
            $notification->actions([
                Action::make('view')
                    ->label($actionLabel ?? 'عرض')
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        $user->notify(new SyncFilamentDatabaseNotification($notification->getDatabaseMessage()));
    }

    /**
     * @param  Collection<int, User>|iterable<User>  $users
     */
    public static function sendToMany(
        iterable $users,
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $url = null,
        ?string $actionLabel = null,
    ): int {
        $count = 0;

        foreach ($users as $user) {
            self::send($user, $title, $body, $status, $url, $actionLabel);
            $count++;
        }

        return $count;
    }

    public static function sendToRole(
        string $role,
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $url = null,
        ?string $actionLabel = null,
    ): int {
        return self::sendToMany(
            User::query()->where('role', $role)->get(),
            $title,
            $body,
            $status,
            $url,
            $actionLabel,
        );
    }

    public static function sendToAll(
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $url = null,
        ?string $actionLabel = null,
    ): int {
        return self::sendToMany(
            User::query()->get(),
            $title,
            $body,
            $status,
            $url,
            $actionLabel,
        );
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\SyncFilamentDatabaseNotification;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class AdminNotificationService
{
    // FCM push is triggered after each database notify (see send()).

    /**
     * @param  array<string, mixed>  $meta  Extra keys merged into the DB notification payload (e.g. order_id).
     */
    public static function send(
        User $user,
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $url = null,
        ?string $actionLabel = null,
        array $meta = [],
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

        $data = $notification->getDatabaseMessage();
        if ($meta !== []) {
            $data = array_merge($data, $meta);
        }

        $user->notify(new SyncFilamentDatabaseNotification($data));

        // Mirror in-app inbox notifications to FCM (best-effort; skipped if not configured).
        try {
            app(FcmPushService::class)->notifyUser($user, $title, $body, $meta);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[FCM] notify skipped', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  Collection<int, User>|iterable<User>  $users
     * @param  array<string, mixed>  $meta
     */
    public static function sendToMany(
        iterable $users,
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $url = null,
        ?string $actionLabel = null,
        array $meta = [],
    ): int {
        $count = 0;

        foreach ($users as $user) {
            self::send($user, $title, $body, $status, $url, $actionLabel, $meta);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function sendToRole(
        string $role,
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $url = null,
        ?string $actionLabel = null,
        array $meta = [],
    ): int {
        return self::sendToMany(
            User::query()->where('role', $role)->get(),
            $title,
            $body,
            $status,
            $url,
            $actionLabel,
            $meta,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function sendToAll(
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $url = null,
        ?string $actionLabel = null,
        array $meta = [],
    ): int {
        return self::sendToMany(
            User::query()->get(),
            $title,
            $body,
            $status,
            $url,
            $actionLabel,
            $meta,
        );
    }
}

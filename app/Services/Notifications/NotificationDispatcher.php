<?php

namespace App\Services\Notifications;

use App\Jobs\SendPushJob;
use App\Models\NotificationInbox;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Catalog\NotificationType;
use App\Notifications\SyncFilamentDatabaseNotification;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NotificationDispatcher
{
    public function __construct(
        protected NotificationTemplateRenderer $renderer,
        protected QuietHoursService $quietHours,
    ) {}

    /**
     * @param  User|Collection<int, User>|iterable<User>  $users
     * @param  array<string, mixed>  $data  Template placeholders + entity_id / screen overrides
     * @param  array{
     *   title?: ?string,
     *   body?: ?string,
     *   send_push?: bool,
     *   dual_write_filament?: bool,
     *   filament_status?: string,
     *   filament_url?: ?string,
     *   filament_action_label?: ?string,
     *   force?: bool
     * }  $options
     * @return list<NotificationInbox>
     */
    public function send(User|iterable $users, NotificationType $type, array $data = [], array $options = []): array
    {
        $collection = $users instanceof User
            ? collect([$users])
            : collect($users)->filter(fn ($u) => $u instanceof User)->values();

        $created = [];

        foreach ($collection as $user) {
            $inbox = $this->sendOne($user, $type, $data, $options);
            if ($inbox) {
                $created[] = $inbox;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function sendOne(User $user, NotificationType $type, array $data = [], array $options = []): ?NotificationInbox
    {
        $def = $type->definition();
        $prefs = NotificationPreference::resolveFor($user, $type);

        $wantInApp = $prefs['in_app'] && in_array('in_app', $def->channels, true);
        $wantPush = ($options['send_push'] ?? true)
            && $prefs['push']
            && in_array('push', $def->channels, true);

        if (! $wantInApp && ! $wantPush && ! ($options['force'] ?? false)) {
            return null;
        }

        $locale = $user->locale
            ?: (string) config('app.locale', 'ar');

        $rendered = $this->renderer->render(
            $def,
            $locale,
            $data,
            $options['title'] ?? null,
            $options['body'] ?? null,
        );

        $entityId = $this->resolveEntityId($data);
        $idempotencyKey = $this->idempotencyKey($type, $entityId, (int) $user->id);

        if ($idempotencyKey) {
            $existing = NotificationInbox::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $payloadData = array_merge($data, [
            'type' => $type->value,
            'entity_id' => $entityId,
            'screen' => $data['screen'] ?? $def->screen,
            'priority' => $def->priority,
        ]);

        $inbox = null;

        if ($wantInApp || ($options['force'] ?? false)) {
            try {
                $inbox = NotificationInbox::query()->create([
                    'user_id' => $user->id,
                    'type' => $type->value,
                    'title' => $rendered['title'],
                    'body' => $rendered['body'],
                    'data' => $payloadData,
                    'priority' => $def->priority,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return NotificationInbox::query()->where('idempotency_key', $idempotencyKey)->first();
            }
        }

        $dualWrite = (bool) ($options['dual_write_filament'] ?? config('notifications.dual_write_filament', true));
        if ($dualWrite && ($wantInApp || ($options['force'] ?? false))) {
            $filamentId = $this->writeFilamentNotification(
                $user,
                $rendered['title'],
                $rendered['body'],
                (string) ($options['filament_status'] ?? 'info'),
                $options['filament_url'] ?? null,
                $options['filament_action_label'] ?? null,
                array_merge($payloadData, [
                    'inbox_id' => $inbox?->id,
                    'notification_type' => $type->value,
                ]),
            );

            if ($inbox && $filamentId) {
                $inbox->forceFill(['filament_notification_id' => $filamentId])->save();
            }
        }

        if ($inbox) {
            $payloadData['inbox_id'] = $inbox->id;
            $inbox->forceFill(['data' => $payloadData])->save();
        }

        if (! $wantPush) {
            return $inbox;
        }

        if (! $inbox) {
            // Push-only: still create a minimal inbox row for logging / badge
            try {
                $inbox = NotificationInbox::query()->create([
                    'user_id' => $user->id,
                    'type' => $type->value,
                    'title' => $rendered['title'],
                    'body' => $rendered['body'],
                    'data' => $payloadData,
                    'priority' => $def->priority,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $payloadData['inbox_id'] = $inbox->id;
                $inbox->forceFill(['data' => $payloadData])->save();
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $inbox = NotificationInbox::query()->where('idempotency_key', $idempotencyKey)->first();
            }
        }

        if (! $inbox) {
            return null;
        }

        if ($def->priority !== 'high' && ! $this->withinDailyRateLimit($user)) {
            Log::info('[notifications] rate limit skipped push', [
                'user_id' => $user->id,
                'type' => $type->value,
                'inbox_id' => $inbox->id,
            ]);

            return $inbox;
        }

        $delayUntil = null;
        if (! $type->isHighPriority()) {
            $delayUntil = $this->quietHours->delayUntil($user);
        }

        $pending = SendPushJob::dispatch($inbox->id)
            ->onQueue((string) config('notifications.queue', 'notifications'))
            ->afterCommit();

        if ($delayUntil) {
            $pending->delay($delayUntil);
        }

        NotificationLog::query()->create([
            'notification_id' => $inbox->id,
            'device_token_id' => null,
            'status' => NotificationLog::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        return $inbox;
    }

    public function withinDailyRateLimit(User $user): bool
    {
        $max = (int) config('notifications.max_normal_push_per_user_per_day', 10);
        if ($max <= 0) {
            return true;
        }

        $key = 'notif:push:day:'.$user->id.':'.now()->toDateString();
        $count = (int) Cache::get($key, 0);

        return $count < $max;
    }

    public function incrementDailyPushCount(User $user): void
    {
        $key = 'notif:push:day:'.$user->id.':'.now()->toDateString();
        if (! Cache::has($key)) {
            Cache::put($key, 1, now()->endOfDay());
        } else {
            Cache::increment($key);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function writeFilamentNotification(
        User $user,
        string $title,
        ?string $body,
        string $status,
        ?string $url,
        ?string $actionLabel,
        array $meta,
    ): ?string {
        $notification = FilamentNotification::make()
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

        $payload = array_merge($notification->getDatabaseMessage(), $meta);
        $user->notify(new SyncFilamentDatabaseNotification($payload));

        $latest = $user->notifications()->latest()->first();

        return $latest?->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveEntityId(array $data): ?string
    {
        foreach (['entity_id', 'order_id', 'invoice_id', 'withdrawal_id', 'id'] as $key) {
            if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null) {
                return (string) $data[$key];
            }
        }

        return null;
    }

    protected function idempotencyKey(NotificationType $type, ?string $entityId, int $userId): ?string
    {
        if ($entityId === null || $entityId === '') {
            return null;
        }

        return substr($type->value.'|'.$entityId.'|'.$userId, 0, 191);
    }
}

<?php

namespace App\Services;

use App\Jobs\ProcessNotificationBroadcastJob;
use App\Models\NotificationBroadcast;
use App\Models\User;
use App\Notifications\Catalog\NotificationType;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AdminNotificationService
{
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
        bool $sendPush = true,
    ): void {
        $type = self::resolveType($meta);

        $data = array_merge($meta, [
            'title' => $title,
            'body' => $body ?? '',
        ]);

        app(NotificationDispatcher::class)->sendOne($user, $type, $data, [
            'title' => $title,
            'body' => $body,
            'send_push' => $sendPush,
            'dual_write_filament' => true,
            'filament_status' => $status,
            'filament_url' => $url,
            'filament_action_label' => $actionLabel,
            'force' => true,
        ]);
    }

    /**
     * Typed catalog send (preferred for domain events).
     *
     * @param  User|Collection<int, User>|iterable<User>  $users
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public static function dispatch(
        User|iterable $users,
        NotificationType $type,
        array $data = [],
        array $options = [],
    ): array {
        return app(NotificationDispatcher::class)->send($users, $type, $data, $options);
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
        bool $sendPush = true,
    ): int {
        $count = 0;

        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }
            self::send($user, $title, $body, $status, $url, $actionLabel, $meta, $sendPush);
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
        bool $sendPush = true,
    ): int {
        return self::sendToMany(
            User::query()->where('role', $role)->where('is_active', true)->get(),
            $title,
            $body,
            $status,
            $url,
            $actionLabel,
            $meta,
            $sendPush,
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
        bool $sendPush = true,
    ): int {
        return self::sendToMany(
            User::query()->where('is_active', true)->get(),
            $title,
            $body,
            $status,
            $url,
            $actionLabel,
            $meta,
            $sendPush,
        );
    }

    /**
     * High-level admin broadcast with library logging.
     *
     * @param  array{
     *   audience?: string,
     *   title: string,
     *   body?: ?string,
     *   status?: string,
     *   url?: ?string,
     *   action_label?: ?string,
     *   role?: ?string,
     *   user_ids?: list<int>,
     *   city_id?: ?int,
     *   city?: ?int,
     *   points_min?: ?int,
     *   points_max?: ?int,
     *   last_active_days?: ?int,
     *   push_type?: ?string,
     *   push_id?: ?string,
     *   send_push?: bool,
     *   meta?: array<string, mixed>,
     *   sent_by?: ?int,
     *   schedule_at?: ?string,
     *   preview_only?: bool,
     * }  $payload
     */
    public static function broadcast(array $payload): NotificationBroadcast|array
    {
        $title = (string) $payload['title'];
        $body = $payload['body'] ?? null;
        $status = (string) ($payload['status'] ?? 'info');
        $url = $payload['url'] ?? null;
        $actionLabel = $payload['action_label'] ?? null;
        $audience = (string) ($payload['audience'] ?? 'custom');
        $sendPush = (bool) ($payload['send_push'] ?? true);
        $meta = (array) ($payload['meta'] ?? []);

        if (! empty($payload['push_type'])) {
            $meta['type'] = $payload['push_type'];
            $meta['entity_type'] = $payload['push_type'];
        }
        if (isset($payload['push_id']) && $payload['push_id'] !== '' && $payload['push_id'] !== null) {
            $meta['id'] = $payload['push_id'];
            $meta['entity_id'] = $payload['push_id'];
            if (($payload['push_type'] ?? null) === 'order') {
                $meta['order_id'] = (int) $payload['push_id'];
            }
        }

        $meta['notification_type'] = NotificationType::CampaignBroadcast->value;
        $meta['title'] = $title;
        $meta['body'] = $body ?? '';

        $segment = [
            'audience' => $audience,
            'role' => $payload['role'] ?? null,
            'user_ids' => array_values(array_map('intval', $payload['user_ids'] ?? [])),
            'city_id' => isset($payload['city_id']) ? (int) $payload['city_id'] : (isset($payload['city']) ? (int) $payload['city'] : null),
            'points_min' => isset($payload['points_min']) ? (int) $payload['points_min'] : null,
            'points_max' => isset($payload['points_max']) ? (int) $payload['points_max'] : null,
            'last_active_days' => isset($payload['last_active_days']) ? (int) $payload['last_active_days'] : null,
        ];

        $query = self::segmentQuery($segment);
        $previewCount = (clone $query)->count();

        if (! empty($payload['preview_only'])) {
            return [
                'preview_count' => $previewCount,
                'segment' => $segment,
            ];
        }

        $scheduleAt = null;
        if (! empty($payload['schedule_at'])) {
            $scheduleAt = Carbon::parse($payload['schedule_at']);
        }

        $broadcast = NotificationBroadcast::query()->create([
            'sent_by' => $payload['sent_by'] ?? auth()->id(),
            'title' => $title,
            'body' => $body,
            'status' => $status,
            'audience' => $audience,
            'role' => $payload['role'] ?? null,
            'user_ids' => $segment['user_ids'],
            'segment' => $segment,
            'recipients_count' => 0,
            'preview_count' => $previewCount,
            'url' => $url,
            'action_label' => $actionLabel,
            'push_type' => $payload['push_type'] ?? 'home',
            'push_id' => isset($payload['push_id']) ? (string) $payload['push_id'] : null,
            'meta' => $meta !== [] ? $meta : null,
            'sent_push' => $sendPush,
            'schedule_at' => $scheduleAt,
            'sent_at' => $scheduleAt ? null : now(),
        ]);

        if ($scheduleAt && $scheduleAt->isFuture()) {
            ProcessNotificationBroadcastJob::dispatch($broadcast->id)
                ->delay($scheduleAt)
                ->onQueue((string) config('notifications.queue', 'notifications'));

            return $broadcast;
        }

        self::executeBroadcast($broadcast);

        return $broadcast->fresh();
    }

    public static function executeBroadcast(NotificationBroadcast $broadcast): NotificationBroadcast
    {
        $segment = (array) ($broadcast->segment ?? []);
        $query = self::segmentQuery([
            'audience' => $broadcast->audience,
            'role' => $broadcast->role,
            'user_ids' => $broadcast->user_ids ?? [],
            'city_id' => $segment['city_id'] ?? null,
            'points_min' => $segment['points_min'] ?? null,
            'points_max' => $segment['points_max'] ?? null,
            'last_active_days' => $segment['last_active_days'] ?? null,
        ]);

        $users = $query->get();
        $meta = (array) ($broadcast->meta ?? []);

        $count = self::sendToMany(
            $users,
            $broadcast->title,
            $broadcast->body,
            $broadcast->status,
            $broadcast->url,
            $broadcast->action_label,
            $meta,
            (bool) $broadcast->sent_push,
        );

        $broadcast->forceFill([
            'recipients_count' => $count,
            'sent_at' => now(),
        ])->save();

        return $broadcast;
    }

    /**
     * @param  array{
     *   audience?: string,
     *   role?: ?string,
     *   user_ids?: list<int>,
     *   city_id?: ?int,
     *   points_min?: ?int,
     *   points_max?: ?int,
     *   last_active_days?: ?int,
     * }  $segment
     */
    public static function segmentQuery(array $segment): Builder
    {
        $query = self::audienceQuery(
            (string) ($segment['audience'] ?? 'all'),
            $segment['role'] ?? null,
            $segment['user_ids'] ?? [],
            isset($segment['city_id']) ? (int) $segment['city_id'] : null,
        );

        $pointsMin = $segment['points_min'] ?? null;
        $pointsMax = $segment['points_max'] ?? null;

        if ($pointsMin !== null || $pointsMax !== null) {
            $query->whereHas('walletAccounts', function (Builder $q) use ($pointsMin, $pointsMax) {
                if ($pointsMin !== null) {
                    $q->where('balance_points', '>=', (int) $pointsMin);
                }
                if ($pointsMax !== null) {
                    $q->where('balance_points', '<=', (int) $pointsMax);
                }
            });
        }

        $lastActiveDays = $segment['last_active_days'] ?? null;
        if ($lastActiveDays !== null && (int) $lastActiveDays > 0) {
            $since = now()->subDays((int) $lastActiveDays);
            $query->where(function (Builder $q) use ($since) {
                $q->whereHas('pushTokens', fn (Builder $t) => $t->where('last_seen_at', '>=', $since))
                    ->orWhere('updated_at', '>=', $since);
            });
        }

        return $query;
    }

    /**
     * @param  list<int|string>  $userIds
     */
    public static function audienceQuery(
        string $audience,
        ?string $role = null,
        array $userIds = [],
        ?int $cityId = null,
    ): Builder {
        $query = User::query()->where('is_active', true);

        $query = match ($audience) {
            'user', 'users' => $query->whereIn('id', $userIds === [] ? [0] : $userIds),
            'role' => $query->where('role', $role ?: 'plumber'),
            'plumbers' => $query->where('role', User::ROLE_PLUMBER),
            'retailers' => $query->where('role', 'retail_trader'),
            'wholesalers' => $query->where('role', 'wholesale_distributor'),
            'all', 'custom' => $query,
            default => $query->whereIn('id', $userIds === [] ? [0] : $userIds),
        };

        if ($cityId) {
            $query->where('city_id', $cityId);
        }

        return $query->orderBy('name');
    }

    public static function previewCount(
        string $audience,
        ?string $role = null,
        array $userIds = [],
        ?int $cityId = null,
    ): int {
        return self::audienceQuery($audience, $role, $userIds, $cityId)->count();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected static function resolveType(array $meta): NotificationType
    {
        $raw = (string) ($meta['notification_type'] ?? $meta['catalog_type'] ?? '');
        if ($raw !== '') {
            $type = NotificationType::tryFrom($raw);
            if ($type) {
                return $type;
            }
        }

        $entity = strtolower((string) ($meta['type'] ?? $meta['entity_type'] ?? $meta['push_type'] ?? ''));

        return match ($entity) {
            'order' => NotificationType::OrderCreated,
            'invoice', 'supply' => NotificationType::InvoiceSubmitted,
            'points' => NotificationType::PointsAdded,
            'withdrawal' => NotificationType::WithdrawalRequested,
            default => NotificationType::CampaignBroadcast,
        };
    }
}

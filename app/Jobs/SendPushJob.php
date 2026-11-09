<?php

namespace App\Jobs;

use App\Models\NotificationInbox;
use App\Models\NotificationLog;
use App\Models\PushToken;
use App\Models\User;
use App\Services\FcmPushService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $inboxId) {}

    public function uniqueId(): string
    {
        return 'send-push-inbox-'.$this->inboxId;
    }

    public function uniqueFor(): int
    {
        return 120;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(FcmPushService $fcm, NotificationDispatcher $dispatcher): void
    {
        $inbox = NotificationInbox::query()->find($this->inboxId);
        if (! $inbox) {
            return;
        }

        $user = User::query()->find($inbox->user_id);
        if (! $user) {
            return;
        }

        if (! $fcm->enabled()) {
            $this->finalizeQueuedLog(
                notificationId: $inbox->id,
                deviceTokenId: null,
                status: NotificationLog::STATUS_FAILED,
                attrs: [
                    'error' => 'FCM disabled or credentials missing',
                    'attempts' => $this->attempts(),
                ],
            );

            return;
        }

        $tokensQuery = PushToken::query()->where('user_id', $user->id);
        if (\Illuminate\Support\Facades\Schema::hasColumn('push_tokens', 'is_active')) {
            $tokensQuery->where('is_active', true);
        }
        $tokens = $tokensQuery->get();

        if ($tokens->isEmpty()) {
            Log::info('[FCM] skipped_no_token', [
                'user_id' => $user->id,
                'inbox_id' => $inbox->id,
            ]);
            $this->finalizeQueuedLog(
                notificationId: $inbox->id,
                deviceTokenId: null,
                status: NotificationLog::STATUS_SKIPPED_NO_TOKEN,
                attrs: [
                    'error' => 'No active device tokens — app must POST /mobile/devices/push-token after login',
                    'attempts' => $this->attempts(),
                ],
            );

            return;
        }

        $unread = NotificationInbox::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        // Also count Laravel unread for badge parity with existing inbox API
        $unread += (int) $user->unreadNotifications()->count();

        $data = $this->buildDataPayload($inbox);
        $channelId = (string) config('notifications.android_channel_id', 'mg_default');

        $tokenStrings = $tokens->pluck('token')->filter()->unique()->values()->all();
        $tokenByValue = $tokens->keyBy('token');

        try {
            $report = $fcm->sendMulticast(
                tokens: $tokenStrings,
                title: $inbox->title,
                body: (string) ($inbox->body ?? ''),
                data: $data,
                androidChannelId: $channelId,
                badge: max(1, $unread),
                highPriority: $inbox->priority === 'high',
            );
        } catch (\Throwable $e) {
            Log::warning('[FCM] multicast error', [
                'inbox_id' => $inbox->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $anySent = false;

        foreach ($report['successes'] as $token => $messageId) {
            $device = $tokenByValue->get($token);
            $this->finalizeQueuedLog(
                notificationId: $inbox->id,
                deviceTokenId: $device?->id,
                status: NotificationLog::STATUS_SENT,
                attrs: [
                    'fcm_message_id' => $messageId,
                    'attempts' => $this->attempts(),
                    'sent_at' => now(),
                    'error' => null,
                ],
            );
            $anySent = true;
        }

        foreach ($report['failures'] as $token => $error) {
            $device = $tokenByValue->get($token);
            $isUnregistered = $this->isUnregisteredError($error);

            if ($isUnregistered && $device) {
                $device->forceFill(['is_active' => false])->save();
            }

            $this->finalizeQueuedLog(
                notificationId: $inbox->id,
                deviceTokenId: $device?->id,
                status: $isUnregistered
                    ? NotificationLog::STATUS_UNREGISTERED
                    : NotificationLog::STATUS_FAILED,
                attrs: [
                    'error' => mb_substr($error, 0, 2000),
                    'attempts' => $this->attempts(),
                ],
            );
        }

        if ($anySent && $inbox->priority !== 'high') {
            $dispatcher->incrementDailyPushCount($user);
        }

        $retryableFailures = array_filter(
            $report['failures'],
            fn (string $error) => ! $this->isUnregisteredError($error),
        );

        if (! $anySent && $retryableFailures !== [] && $this->attempts() < $this->tries) {
            throw new \RuntimeException('FCM multicast: all tokens failed');
        }
    }

    /**
     * Prefer updating an existing queued row (same notification + device) over inserting.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function finalizeQueuedLog(int $notificationId, ?int $deviceTokenId, string $status, array $attrs = []): void
    {
        $payload = array_merge($attrs, [
            'status' => $status,
            'device_token_id' => $deviceTokenId,
        ]);

        $queued = NotificationLog::query()
            ->where('notification_id', $notificationId)
            ->where('status', NotificationLog::STATUS_QUEUED)
            ->when(
                $deviceTokenId !== null,
                fn ($q) => $q->where(function ($inner) use ($deviceTokenId) {
                    $inner->where('device_token_id', $deviceTokenId)
                        ->orWhereNull('device_token_id');
                }),
                fn ($q) => $q->whereNull('device_token_id')
            )
            ->orderByRaw('case when device_token_id is null then 1 else 0 end')
            ->orderBy('id')
            ->first();

        if ($queued) {
            $queued->fill($payload)->save();

            return;
        }

        NotificationLog::query()->create(array_merge([
            'notification_id' => $notificationId,
            'device_token_id' => $deviceTokenId,
            'status' => $status,
            'attempts' => $attrs['attempts'] ?? 0,
        ], $attrs));
    }

    /**
     * @return array<string, string>
     */
    protected function buildDataPayload(NotificationInbox $inbox): array
    {
        $raw = (array) ($inbox->data ?? []);

        $screen = (string) ($raw['screen'] ?? 'home');
        $data = [
            'type' => (string) ($raw['type'] ?? $inbox->type),
            'entity_id' => (string) ($raw['entity_id'] ?? $raw['id'] ?? ''),
            'screen' => $screen,
            'inbox_id' => (string) $inbox->id,
        ];

        // Keep legacy keys for existing Flutter clients
        if ($data['entity_id'] !== '') {
            $data['id'] = $data['entity_id'];
        }
        if (isset($raw['order_id'])) {
            $data['order_id'] = (string) $raw['order_id'];
        }
        if (isset($raw['link'])) {
            $data['link'] = (string) $raw['link'];
        }

        $deepLink = $raw['deep_link'] ?? null;
        if (! filled($deepLink)) {
            if ($screen === \App\Services\PendingReceiptsService::SCREEN) {
                $entityId = isset($raw['entity_id']) ? (int) $raw['entity_id'] : 0;
                $deepLink = app(\App\Services\PendingReceiptsService::class)
                    ->deepLink($entityId > 0 ? $entityId : null);
            } elseif (isset($raw['link']) && is_string($raw['link']) && str_starts_with($raw['link'], 'mgplastic://')) {
                $deepLink = $raw['link'];
            }
        }
        if (filled($deepLink)) {
            $data['deep_link'] = (string) $deepLink;
        }

        // Strip empty entity_id for cleaner payloads
        if ($data['entity_id'] === '') {
            unset($data['entity_id']);
        }

        return array_map(
            fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v),
            $data
        );
    }

    protected function isUnregisteredError(string $error): bool
    {
        $message = strtoupper($error);
        foreach (['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND', 'INVALID_REGISTRATION', 'REGISTRATION_TOKEN_NOT_REGISTERED'] as $code) {
            if (str_contains($message, $code)) {
                return true;
            }
        }

        return false;
    }
}

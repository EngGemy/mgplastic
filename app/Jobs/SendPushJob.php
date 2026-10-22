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
use Kreait\Firebase\Exception\MessagingException;

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
            NotificationLog::query()->create([
                'notification_id' => $inbox->id,
                'status' => NotificationLog::STATUS_FAILED,
                'error' => 'FCM disabled or credentials missing',
                'attempts' => $this->attempts(),
            ]);

            return;
        }

        $tokens = PushToken::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        if ($tokens->isEmpty()) {
            NotificationLog::query()->create([
                'notification_id' => $inbox->id,
                'status' => NotificationLog::STATUS_FAILED,
                'error' => 'No active device tokens',
                'attempts' => $this->attempts(),
            ]);

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
            NotificationLog::query()->create([
                'notification_id' => $inbox->id,
                'device_token_id' => $device?->id,
                'status' => NotificationLog::STATUS_SENT,
                'fcm_message_id' => $messageId,
                'attempts' => $this->attempts(),
                'sent_at' => now(),
            ]);
            $anySent = true;
        }

        foreach ($report['failures'] as $token => $error) {
            $device = $tokenByValue->get($token);
            $isUnregistered = $this->isUnregisteredError($error);

            if ($isUnregistered && $device) {
                $device->forceFill(['is_active' => false])->save();
            }

            NotificationLog::query()->create([
                'notification_id' => $inbox->id,
                'device_token_id' => $device?->id,
                'status' => $isUnregistered
                    ? NotificationLog::STATUS_UNREGISTERED
                    : NotificationLog::STATUS_FAILED,
                'error' => mb_substr($error, 0, 2000),
                'attempts' => $this->attempts(),
            ]);
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
     * @return array<string, string>
     */
    protected function buildDataPayload(NotificationInbox $inbox): array
    {
        $raw = (array) ($inbox->data ?? []);

        $data = [
            'type' => (string) ($raw['type'] ?? $inbox->type),
            'entity_id' => (string) ($raw['entity_id'] ?? $raw['id'] ?? ''),
            'screen' => (string) ($raw['screen'] ?? 'home'),
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

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BroadcastNotificationRequest;
use App\Models\NotificationInbox;
use App\Models\NotificationLog;
use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationAdminController extends Controller
{
    use ApiResponds;

    public function broadcast(BroadcastNotificationRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['sent_by'] = $request->user()->id;

        $result = AdminNotificationService::broadcast($payload);

        if (is_array($result)) {
            return $this->success($result, 'معاينة الجمهور');
        }

        return $this->success([
            'broadcast' => [
                'id' => $result->id,
                'title' => $result->title,
                'recipients_count' => $result->recipients_count,
                'preview_count' => $result->preview_count,
                'schedule_at' => optional($result->schedule_at)?->toIso8601String(),
                'sent_at' => optional($result->sent_at)?->toIso8601String(),
                'segment' => $result->segment,
            ],
        ], $result->sent_at ? 'تم إرسال البث' : 'تمت جدولة البث');
    }

    /**
     * Delivery stats per notification type.
     * GET /api/admin/notifications/stats
     */
    public function stats(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return $this->error('غير مصرّح', 403);
        }

        $sent = NotificationLog::query()
            ->select('notifications_inbox.type', DB::raw('count(*) as total'))
            ->join('notifications_inbox', 'notifications_inbox.id', '=', 'notification_logs.notification_id')
            ->where('notification_logs.status', NotificationLog::STATUS_SENT)
            ->groupBy('notifications_inbox.type')
            ->pluck('total', 'type');

        $failed = NotificationLog::query()
            ->select('notifications_inbox.type', DB::raw('count(*) as total'))
            ->join('notifications_inbox', 'notifications_inbox.id', '=', 'notification_logs.notification_id')
            ->whereIn('notification_logs.status', [
                NotificationLog::STATUS_FAILED,
                NotificationLog::STATUS_UNREGISTERED,
            ])
            ->groupBy('notifications_inbox.type')
            ->pluck('total', 'type');

        $opened = NotificationInbox::query()
            ->select('type', DB::raw('count(*) as total'))
            ->whereNotNull('opened_at')
            ->groupBy('type')
            ->pluck('total', 'type');

        $types = collect($sent->keys())
            ->merge($failed->keys())
            ->merge($opened->keys())
            ->unique()
            ->values();

        $rows = $types->map(fn (string $type) => [
            'type' => $type,
            'sent' => (int) ($sent[$type] ?? 0),
            'failed' => (int) ($failed[$type] ?? 0),
            'opened' => (int) ($opened[$type] ?? 0),
        ])->values();

        return $this->success([
            'by_type' => $rows,
            'totals' => [
                'sent' => (int) $sent->sum(),
                'failed' => (int) $failed->sum(),
                'opened' => (int) $opened->sum(),
            ],
        ]);
    }

    protected function isAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && in_array($user->role, ['super_admin', 'admin'], true);
    }
}

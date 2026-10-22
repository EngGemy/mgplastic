<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\UpdateNotificationPreferencesRequest;
use App\Http\Resources\Mobile\NotificationResource;
use App\Models\NotificationInbox;
use App\Models\NotificationPreference;
use App\Notifications\Catalog\NotificationCatalog;
use App\Notifications\Catalog\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Validator;

/**
 * Full database-notifications inbox for mobile (plumber, trader, distributor, …).
 * Backed by Laravel `notifications` table (UUID rows on the authenticated user).
 */
class NotificationController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        $query = $request->user()->notifications()->latest();

        if ($request->boolean('unread_only') || $request->string('filter')->toString() === 'unread') {
            $query->whereNull('read_at');
        } elseif ($request->boolean('read_only') || $request->string('filter')->toString() === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            $query->where('data->status', $status);
        }

        if ($request->filled('order_id')) {
            $query->where('data->order_id', (int) $request->integer('order_id'));
        }

        if ($request->filled('entity_type')) {
            $query->where('data->entity_type', $request->string('entity_type')->toString());
        }

        if ($request->filled('q') || $request->filled('search')) {
            $term = trim((string) ($request->query('q') ?? $request->query('search')));
            if ($term !== '') {
                $like = '%'.$term.'%';
                // `data` is JSON text — substring match covers title/body safely across DBs
                $query->where('data', 'like', $like);
            }
        }

        $page = $query->paginate($perPage);
        $user = $request->user();

        $unreadCount = (int) $user->unreadNotifications()->count();

        return $this->success([
            'items' => NotificationResource::collection($page->items())->resolve(),
            'unread_count' => $unreadCount,
            'summary' => [
                'total' => (int) $user->notifications()->count(),
                'unread' => $unreadCount,
                'read' => (int) $user->notifications()->whereNotNull('read_at')->count(),
            ],
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'total' => (int) $user->notifications()->count(),
            'unread' => (int) $user->unreadNotifications()->count(),
            'read' => (int) $user->notifications()->whereNotNull('read_at')->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'count' => (int) $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $notification = $this->findOwned($request, $id);

        if (! $notification) {
            return $this->error('الإشعار غير موجود', 404);
        }

        return $this->success(new NotificationResource($notification));
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->findOwned($request, $id);

        if (! $notification) {
            return $this->error('الإشعار غير موجود', 404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        $this->syncInboxRead($request->user()->id, $notification);

        return $this->success(
            new NotificationResource($notification->fresh()),
            'تم تعليم الإشعار كمقروء',
        );
    }

    public function markUnread(Request $request, string $id): JsonResponse
    {
        $notification = $this->findOwned($request, $id);

        if (! $notification) {
            return $this->error('الإشعار غير موجود', 404);
        }

        if ($notification->read_at !== null) {
            $notification->forceFill(['read_at' => null])->save();
        }

        return $this->success(
            new NotificationResource($notification->fresh()),
            'تم تعليم الإشعار كغير مقروء',
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = (int) $request->user()->unreadNotifications()->count();
        $request->user()->unreadNotifications->markAsRead();

        NotificationInbox::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'opened_at' => now(),
            ]);

        return $this->success([
            'marked' => $count,
            'unread' => 0,
            'unread_count' => 0,
        ], 'تم تعليم جميع الإشعارات كمقروءة');
    }

    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'preferences' => NotificationPreference::listForUser($user),
            'quiet_hours' => [
                'quiet_from' => $user->quiet_from,
                'quiet_to' => $user->quiet_to,
                'timezone' => $user->timezone,
                'locale' => $user->locale,
            ],
            'catalog' => NotificationCatalog::toArray(),
        ]);
    }

    public function updatePreferences(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        if (isset($validated['preferences']) && is_array($validated['preferences'])) {
            foreach ($validated['preferences'] as $row) {
                $type = NotificationType::tryFrom((string) ($row['type'] ?? ''));
                if (! $type) {
                    continue;
                }

                NotificationPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'type' => $type->value],
                    [
                        'push' => (bool) ($row['push'] ?? true),
                        'in_app' => (bool) ($row['in_app'] ?? true),
                        'email' => (bool) ($row['email'] ?? false),
                    ],
                );
            }
        }

        $quiet = $validated['quiet_hours'] ?? null;
        if (is_array($quiet)) {
            $user->forceFill([
                'quiet_from' => $quiet['quiet_from'] ?? $user->quiet_from,
                'quiet_to' => $quiet['quiet_to'] ?? $user->quiet_to,
                'timezone' => $quiet['timezone'] ?? $user->timezone,
                'locale' => $quiet['locale'] ?? $user->locale,
            ])->save();
        }

        return $this->success([
            'preferences' => NotificationPreference::listForUser($user->fresh()),
            'quiet_hours' => [
                'quiet_from' => $user->quiet_from,
                'quiet_to' => $user->quiet_to,
                'timezone' => $user->timezone,
                'locale' => $user->locale,
            ],
        ], 'تم حفظ تفضيلات الإشعارات');
    }

    /** Body: { "ids": ["uuid", ...] } */
    public function markManyRead(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        $ids = $v->validated()['ids'];
        $rows = $request->user()->notifications()
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->get();

        $rows->markAsRead();

        return $this->success([
            'marked' => $rows->count(),
            'requested' => count($ids),
        ], 'تم تعليم الإشعارات المحددة كمقروءة');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $notification = $this->findOwned($request, $id);

        if (! $notification) {
            return $this->error('الإشعار غير موجود', 404);
        }

        $notification->delete();

        return $this->success(null, 'تم حذف الإشعار');
    }

    /** Body: { "ids": ["uuid", ...] } */
    public function destroyMany(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات غير صالحة', 422, $v->errors());
        }

        $deleted = $request->user()->notifications()
            ->whereIn('id', $v->validated()['ids'])
            ->delete();

        return $this->success([
            'deleted' => (int) $deleted,
        ], 'تم حذف الإشعارات المحددة');
    }

    public function destroyRead(Request $request): JsonResponse
    {
        $deleted = $request->user()->notifications()
            ->whereNotNull('read_at')
            ->delete();

        return $this->success([
            'deleted' => (int) $deleted,
        ], 'تم حذف الإشعارات المقروءة');
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $deleted = $request->user()->notifications()->delete();

        return $this->success([
            'deleted' => (int) $deleted,
        ], 'تم حذف كل الإشعارات');
    }

    protected function findOwned(Request $request, string $id): ?DatabaseNotification
    {
        return $request->user()->notifications()->whereKey($id)->first();
    }

    protected function syncInboxRead(int $userId, DatabaseNotification $notification): void
    {
        $data = (array) $notification->data;
        $inboxId = $data['inbox_id'] ?? null;

        $query = NotificationInbox::query()->where('user_id', $userId)->whereNull('read_at');

        if ($inboxId) {
            $query->where('id', (int) $inboxId);
        } elseif ($notification->id) {
            $query->where('filament_notification_id', $notification->id);
        } else {
            return;
        }

        $query->update([
            'read_at' => now(),
            'opened_at' => now(),
        ]);
    }
}

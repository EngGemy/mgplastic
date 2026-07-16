<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\NotificationResource;
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

        return $this->success([
            'items' => NotificationResource::collection($page->items())->resolve(),
            'summary' => [
                'total' => (int) $user->notifications()->count(),
                'unread' => (int) $user->unreadNotifications()->count(),
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

        return $this->success([
            'marked' => $count,
            'unread' => 0,
        ], 'تم تعليم جميع الإشعارات كمقروءة');
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
}

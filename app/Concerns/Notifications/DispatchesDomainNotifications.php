<?php

namespace App\Concerns\Notifications;

use App\Models\User;
use App\Notifications\Catalog\NotificationType;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Thin, reusable entry-point for domain → catalog push/in-app notifications.
 *
 * SOLID notes:
 * - S: only adapts domain callers to NotificationDispatcher (no FCM / prefs logic here).
 * - O: new events = new NotificationType + data; callers stay unchanged.
 * - L: any class can use the trait without altering dispatcher contracts.
 * - I: expose only notify* helpers callers need.
 * - D: depends on NotificationDispatcher abstraction via the container.
 */
trait DispatchesDomainNotifications
{
    /**
     * @param  User|Collection<int, User>|iterable<User>|null  $recipients
     * @param  array<string, mixed>  $data
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
     */
    protected function notifyDomain(
        User|iterable|null $recipients,
        NotificationType $type,
        array $data = [],
        array $options = [],
    ): void {
        if ($recipients === null) {
            return;
        }

        $users = $recipients instanceof User
            ? collect([$recipients])
            : collect($recipients)->filter(fn ($u) => $u instanceof User)->values();

        if ($users->isEmpty()) {
            return;
        }

        $options = array_merge([
            'dual_write_filament' => true,
            'force' => true,
            'send_push' => true,
        ], $options);

        try {
            app(NotificationDispatcher::class)->send($users, $type, $data, $options);
        } catch (\Throwable $e) {
            Log::warning('[notifications] domain notify failed', [
                'type' => $type->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    protected function notifyDomainRole(
        string $role,
        NotificationType $type,
        array $data = [],
        array $options = [],
    ): void {
        $users = User::query()
            ->where('role', $role)
            ->where('is_active', true)
            ->get();

        $this->notifyDomain($users, $type, $data, $options);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    protected function notifyDomainAdmins(
        NotificationType $type,
        array $data = [],
        array $options = [],
    ): void {
        $this->notifyDomainRole('super_admin', $type, $data, $options);
        $this->notifyDomainRole('admin', $type, $data, $options);
    }
}

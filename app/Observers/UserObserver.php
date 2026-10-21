<?php

namespace App\Observers;

use App\Models\User;
use App\Services\ForceLogoutService;
use App\Services\NetworkCodeService;
use App\Services\WhatsAppBusinessAlerts;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    public function deleting(User $user): void
    {
        // Must run before the row is gone so tokens/sessions can be keyed by user id.
        try {
            app(ForceLogoutService::class)->revokeAll($user);
        } catch (\Throwable $e) {
            Log::warning('[Auth] force logout on user delete failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('is_active') && ! $user->is_active) {
            try {
                app(ForceLogoutService::class)->revokeAll($user);
            } catch (\Throwable $e) {
                Log::warning('[Auth] force logout on deactivate failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $user->wasChanged('role')) {
            return;
        }

        if (! in_array($user->role, ['wholesale_distributor', 'retail_trader', 'plumber'], true)) {
            return;
        }

        if (filled($user->network_code)) {
            return;
        }

        app(NetworkCodeService::class)->ensure($user);
    }

    public function created(User $user): void
    {
        if (! in_array($user->role, ['wholesale_distributor', 'retail_trader', 'plumber'], true)) {
            return;
        }

        if (! filled($user->network_code)) {
            app(NetworkCodeService::class)->ensure($user);
            $user->refresh();
        }

        // Welcome WhatsApp on real HTTP registrations (skip seeders / console).
        if (! app()->runningInConsole() && filled($user->phone)) {
            try {
                app(WhatsAppBusinessAlerts::class)->welcome($user->fresh() ?? $user);
            } catch (\Throwable $e) {
                Log::warning('[WhatsApp] welcome skipped', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

}

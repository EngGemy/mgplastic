<?php

namespace App\Observers;

use App\Models\User;
use App\Services\NetworkCodeService;

class UserObserver
{
    public function created(User $user): void
    {
        if (! in_array($user->role, ['wholesale_distributor', 'retail_trader', 'plumber'], true)) {
            return;
        }

        if (filled($user->network_code)) {
            return;
        }

        app(NetworkCodeService::class)->ensure($user);
    }

    public function updated(User $user): void
    {
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
}

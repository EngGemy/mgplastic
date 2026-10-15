<?php

namespace App\Support;

use App\Models\User;

class PointsVisibility
{
    /**
     * Per-unit product points are admin-only.
     * Network portals (wholesale / retail / plumber) see order/cart totals only.
     */
    public static function canSeePerUnit(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin'], true);
    }

    public static function hidePerUnit(?User $user = null): bool
    {
        return ! static::canSeePerUnit($user);
    }
}

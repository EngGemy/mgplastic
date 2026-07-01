<?php

namespace App\Filament\Concerns;

/**
 * يُستخدم في أي Resource مقتصر على super_admin و admin فقط.
 * أضف: use AdminOnlyResource; داخل الـ Resource class.
 */
trait AdminOnlyResource
{
    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public static function canCreate(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public static function canEdit($record): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin'], true);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }
}

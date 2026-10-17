<?php

namespace App\Http\Middleware;

use App\Support\AdminPanelPath;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Wrong-panel users are sent to their own panel.
 * Filament's default middleware answers that case with a blank 403.
 */
class AuthenticatePanel extends FilamentAuthenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);

            return;
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        $user = $guard->user();
        $panel = Filament::getCurrentPanel();

        if ($user instanceof FilamentUser && $user->canAccessPanel($panel)) {
            return;
        }

        $target = $this->homeForRole((string) ($user->role ?? ''));
        $here = match ($panel?->getId()) {
            'admin' => AdminPanelPath::url(),
            'warehouse' => '/warehouse',
            'distributor' => '/distributor',
            'trader' => '/trader',
            default => null,
        };

        if ($target !== null && $target !== $here) {
            throw new HttpResponseException(redirect($target));
        }

        abort(403, 'لا تملك صلاحية دخول هذه اللوحة');
    }

    private function homeForRole(string $role): ?string
    {
        if ($role === 'wholesale_distributor' && auth()->user()?->isCentralWarehouse()) {
            return '/warehouse';
        }

        return match ($role) {
            'super_admin', 'admin' => AdminPanelPath::url(),
            'wholesale_distributor' => '/distributor',
            'retail_trader' => '/trader',
            default => null,
        };
    }
}

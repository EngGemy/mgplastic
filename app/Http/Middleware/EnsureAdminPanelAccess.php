<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdminPanelAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if ($user->role === 'wholesale_distributor') {
            return redirect('/distributor');
        }

        if ($user->role === 'retail_trader') {
            return redirect('/trader');
        }

        if (! in_array($user->role, ['super_admin', 'admin'], true)) {
            abort(403);
        }

        return $next($request);
    }
}

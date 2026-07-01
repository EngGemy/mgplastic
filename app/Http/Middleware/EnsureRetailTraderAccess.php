<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRetailTraderAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return redirect('/admin');
        }

        if ($user->role === 'wholesale_distributor') {
            return redirect('/distributor');
        }

        if ($user->role !== 'retail_trader') {
            abort(403, 'مخصص لتجار القطاعي فقط');
        }

        return $next($request);
    }
}

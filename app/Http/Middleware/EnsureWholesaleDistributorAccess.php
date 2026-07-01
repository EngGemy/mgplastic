<?php

namespace App\Http\Middleware;

use App\Support\AdminPanelPath;
use Closure;
use Illuminate\Http\Request;

class EnsureWholesaleDistributorAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return redirect(AdminPanelPath::url());
        }

        if ($user->role === 'retail_trader') {
            return redirect('/trader');
        }

        if ($user->role !== 'wholesale_distributor') {
            abort(403, 'مخصص لموزعي الجملة فقط');
        }

        return $next($request);
    }
}

<?php
// app/Http/Middleware/EnsurePhoneVerified.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePhoneVerified
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !$user->is_phone_verified) {
            return response()->json([
                'status' => false,
                'message' => 'يجب توثيق رقم الهاتف أولاً',
            ], 403);
        }
        return $next($request);
    }
}

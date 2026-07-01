<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next)
    {
        $accept = $request->header('Accept-Language');   // e.g. "ar" or "ar-EG" or "ar;q=0.9,en;q=0.8"
        $supported = config('app.locales', ['en','ar']);

        $locale = null;
        if ($accept) {
            // take the first language code, normalize to base lang (e.g., "ar-EG" -> "ar")
            $lang = Str::of($accept)->before(',')->before(';')->before('-')->before('_')->lower()->value();
            if (in_array($lang, $supported, true)) {
                $locale = $lang;
            }
        }

        app()->setLocale($locale ?: config('app.locale', 'en'));

        return $next($request);
    }
}

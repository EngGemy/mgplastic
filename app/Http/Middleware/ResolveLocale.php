<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;
// app/Http/Middleware/ResolveLocale.php
use Illuminate\Support\Facades\Log;
class ResolveLocale
{
    /** @var string[] allowed app languages */
    protected array $supported = ['en', 'ar'];



    public function handle(Request $request, Closure $next)
    {
        $raw = $request->header('Accept-Language')
            ?? $request->header('X-Locale')
            ?? $request->query('lang', 'en');

        $first  = strtolower(\Illuminate\Support\Str::of($raw)->before(',')->before(';')->value() ?: 'en');
        $base   = \Illuminate\Support\Str::of($first)->before('-')->before('_')->value();
        $locale = in_array($base, ['en','ar'], true) ? $base : 'en';

        app()->setLocale($locale);
        \Carbon\Carbon::setLocale($locale);
        $request->attributes->set('resolved_locale', $locale);

        Log::info('[ResolveLocale] set', [
            'accept_language' => $request->header('Accept-Language'),
            'resolved' => $locale,
            'path' => $request->path(),
        ]);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale); // <-- visible in Postman
        return $response;
    }

}

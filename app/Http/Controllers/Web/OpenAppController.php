<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\DeepLink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OpenAppController extends Controller
{
    public function __invoke(Request $request, string $type, ?string $id = null): Response
    {
        if (! DeepLink::isAllowedType($type)) {
            abort(404);
        }

        $id = $id ?? '0';
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            abort(404);
        }

        $type = DeepLink::normalizeType($type);
        $ua = strtolower((string) $request->userAgent());
        $isAndroid = str_contains($ua, 'android');
        $isIos = str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod');
        $isWhatsApp = str_contains($ua, 'whatsapp') || str_contains($ua, 'fbav') || str_contains($ua, 'fban');

        $httpsUrl = DeepLink::for($type, $id);
        $customUrl = DeepLink::customScheme($type, $id);
        $intentUrl = DeepLink::androidIntent($type, $id);
        $appPath = DeepLink::appPath($type, $id);

        return response()
            ->view('web.open-app', [
                'type' => $type,
                'id' => $id,
                'appPath' => $appPath,
                'httpsUrl' => $httpsUrl,
                'customUrl' => $customUrl,
                'intentUrl' => $intentUrl,
                'isAndroid' => $isAndroid,
                'isIos' => $isIos,
                'isWhatsApp' => $isWhatsApp,
                'androidStore' => (string) config('app_links.android_store_url'),
                'iosStore' => (string) config('app_links.ios_store_url'),
                'company' => 'MG للصناعات البلاستيكية',
                'scheme' => (string) config('app_links.scheme', 'mgplastic'),
                'package' => (string) config('app_links.package', 'com.MgTools'),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            // Help some Android WebViews resolve App Links
            ->header('X-Content-Type-Options', 'nosniff');
    }
}

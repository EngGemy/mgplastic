<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\DeepLink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class OpenAppController extends Controller
{
    public function __invoke(Request $request, string $type, string $id): View|Response
    {
        if (! DeepLink::isAllowedType($type)) {
            abort(404);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            abort(404);
        }

        $type = DeepLink::normalizeType($type);
        $ua = strtolower((string) $request->userAgent());
        $isAndroid = str_contains($ua, 'android');
        $isIos = str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod');

        $httpsUrl = DeepLink::for($type, $id);
        $customUrl = DeepLink::customScheme($type, $id);
        $intentUrl = DeepLink::androidIntent($type, $id);

        return response()
            ->view('web.open-app', [
                'type' => $type,
                'id' => $id,
                'httpsUrl' => $httpsUrl,
                'customUrl' => $customUrl,
                'intentUrl' => $intentUrl,
                'isAndroid' => $isAndroid,
                'isIos' => $isIos,
                'androidStore' => (string) config('app_links.android_store_url'),
                'iosStore' => (string) config('app_links.ios_store_url'),
                'company' => 'MG للصناعات البلاستيكية',
            ])
            ->header('Cache-Control', 'no-store');
    }
}

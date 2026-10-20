<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class WellKnownController extends Controller
{
    /**
     * Android App Links — package + SHA-256 from config/app_links.php (.env).
     */
    public function assetLinks(): JsonResponse
    {
        $package = (string) config('app_links.package');
        $fps = (array) config('app_links.android_sha256_fingerprints', []);

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => array_values($fps),
            ],
        ]], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Apple Universal Links — appID must be TeamID.iOSBundle (not the Android package).
     */
    public function appleAppSiteAssociation(): Response
    {
        $appId = trim((string) config('app_links.apple_app_id', ''));
        if ($appId === '') {
            $team = trim((string) config('app_links.apple_team_id', ''));
            $bundle = trim((string) config('app_links.ios_bundle_id', 'com.mgPlumbers.app'));
            $appId = $team !== '' ? $team.'.'.$bundle : $bundle;
        }

        $payload = [
            'applinks' => [
                'apps' => [],
                'details' => [[
                    'appID' => $appId,
                    'paths' => ['/open/*'],
                ]],
            ],
        ];

        return response(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 200, [
            // Apple requires application/json (no charset); no auth; HTTPS only.
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}

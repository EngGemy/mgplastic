<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class WellKnownController extends Controller
{
    /**
     * Android App Links — replace APP_ANDROID_SHA256 placeholder before production.
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
        ]])->header('Content-Type', 'application/json');
    }

    /**
     * Apple Universal Links — set APP_APPLE_TEAM_ID / APP_APPLE_APP_ID before production.
     */
    public function appleAppSiteAssociation(): Response
    {
        $appId = (string) config('app_links.apple_app_id');
        if ($appId === '') {
            $team = (string) config('app_links.apple_team_id', 'TEAMIDXXXX');
            $package = (string) config('app_links.package', 'com.mgplastic.app');
            $appId = $team.'.'.$package;
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
            'Content-Type' => 'application/json',
        ]);
    }
}

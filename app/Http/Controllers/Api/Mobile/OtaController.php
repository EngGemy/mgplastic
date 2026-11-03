<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\OtaCheckRequest;
use App\Models\OtaUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class OtaController extends Controller
{
    use ApiResponds;

    /**
     * GET /api/v1/mobile/ota/check
     *
     * Public — called on every app open, before login.
     */
    public function check(OtaCheckRequest $request): JsonResponse
    {
        $platform = (string) $request->validated('platform');
        $nativeBuild = (string) $request->validated('native_build');
        $bundleVersion = (int) $request->validated('bundle_version');

        $generation = (int) Cache::get('ota:check:generation', 1);
        $cacheKey = sprintf('ota:check:%d:%s:%s:%d', $generation, $platform, $nativeBuild, $bundleVersion);

        $data = Cache::remember($cacheKey, 60, function () use ($platform, $nativeBuild, $bundleVersion) {
            $update = OtaUpdate::query()
                ->where('platform', $platform)
                ->where('native_build', $nativeBuild)
                ->where('is_active', true)
                ->where('version', '>', $bundleVersion)
                ->orderByDesc('version')
                ->first();

            if (! $update) {
                return ['update_available' => false];
            }

            $downloadUrl = $update->downloadUrl();

            // Missing or non-https file → treat as no update (app would ignore it anyway).
            if ($downloadUrl === null || ! str_starts_with($downloadUrl, 'https://')) {
                return ['update_available' => false];
            }

            return [
                'update_available' => true,
                'version' => (int) $update->version,
                'download_url' => $downloadUrl,
            ];
        });

        return $this->success($data, '');
    }
}

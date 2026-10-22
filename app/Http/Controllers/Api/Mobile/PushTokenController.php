<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\FcmPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    use ApiResponds;

    public function store(Request $request, FcmPushService $fcm): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'device_id' => ['required', 'string', 'min:4', 'max:191'],
        ]);

        $fcm->register(
            $request->user(),
            $data['token'],
            $data['platform'],
            $data['device_id'],
        );

        return $this->success(null, 'تم حفظ رمز الإشعارات');
    }

    public function destroy(Request $request, FcmPushService $fcm): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:191'],
        ]);

        $fcm->forgetDevice($data['device_id']);

        return $this->success(null, 'تم حذف رمز الإشعارات');
    }
}

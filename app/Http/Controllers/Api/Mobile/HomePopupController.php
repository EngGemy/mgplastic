<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\HomePopup;
use App\Models\HomePopupEvent;
use App\Services\HomeMobileLayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HomePopupController extends Controller
{
    use ApiResponds;

    /**
     * GET /api/v1/mobile/home/popup
     * Same payload as data.popup inside home/layout (compat).
     */
    public function show(Request $request, HomeMobileLayoutService $layout): JsonResponse
    {
        $user = auth('sanctum')->user() ?? $request->user();
        $roleHint = $request->query('role');
        $role = $layout->normalizeRole(is_string($roleHint) ? $roleHint : null, $user);
        $locale = $this->localeFromRequest($request);

        $popup = HomePopup::mobilePayloadFor($role, $user, $locale);

        if ($popup === null) {
            return $this->success(null, 'لا يوجد إعلان');
        }

        return $this->success($popup, 'إعلان الرئيسية');
    }

    /**
     * POST /api/v1/mobile/home/popup/{id}/event
     * Auth optional — guests send device_id only.
     */
    public function event(Request $request, int $id, HomeMobileLayoutService $layout): Response|JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'in:view,click,dismiss'],
            'button_id' => ['nullable', 'string', 'max:64'],
            'version' => ['nullable', 'integer', 'min:1'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'role' => ['nullable', 'string', 'max:32'],
        ]);

        $popup = HomePopup::query()->find($id);
        if (! $popup) {
            // Soft-ack — client ignores failures.
            return response()->noContent();
        }

        $user = auth('sanctum')->user() ?? $request->user();
        $role = $layout->normalizeRole(
            is_string($data['role'] ?? null) ? $data['role'] : $request->query('role'),
            $user
        );

        if (($data['event'] ?? '') === 'click' && blank($data['button_id'] ?? null)) {
            $data['button_id'] = 'unknown';
        }

        HomePopupEvent::query()->create([
            'home_popup_id' => $popup->id,
            'version' => (int) ($data['version'] ?? $popup->version ?? 1),
            'event' => $data['event'],
            'button_id' => $data['event'] === 'click' ? ($data['button_id'] ?? null) : null,
            'user_id' => $user?->id,
            'role' => $role,
            'device_id' => $data['device_id'] ?? null,
            'created_at' => now(),
        ]);

        return response()->noContent();
    }

    protected function localeFromRequest(Request $request): string
    {
        $raw = strtolower(str_replace('_', '-', (string) $request->header('Accept-Language', 'ar')));

        return str_starts_with($raw, 'en') ? 'en' : 'ar';
    }
}

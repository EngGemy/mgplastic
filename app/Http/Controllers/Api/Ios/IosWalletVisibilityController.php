<?php

namespace App\Http\Controllers\Api\Ios;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AppFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global wallet visibility flag for mobile apps (iOS / Android).
 *
 * Apps should call GET before rendering the wallet tab.
 * Admins toggle via PUT/POST (auth:sanctum + admin role).
 */
class IosWalletVisibilityController extends Controller
{
    use ApiResponds;

    public const FLAG_KEY = 'ios_wallet_enabled';

    /**
     * GET /api/ios/wallet-visibility
     * GET /api/v1/mobile/wallet-visibility
     */
    public function show(Request $request): JsonResponse
    {
        $show = AppFlag::getBool(self::FLAG_KEY, true);

        return $this->success([
            'show_wallet' => $show,
            'enabled' => $show,
            'message' => $show
                ? 'المحفظة ظاهرة في التطبيق'
                : 'المحفظة مخفية في التطبيق',
        ]);
    }

    /**
     * PUT|POST /api/admin/ios/wallet-visibility
     * PUT|POST /api/v1/mobile/admin/wallet-visibility
     *
     * Body: { "enabled": true|false }  OR  { "show_wallet": true|false }
     */
    public function update(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return $this->error('غير مصرّح — صلاحيات الإدارة مطلوبة', 403);
        }

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'show_wallet' => ['nullable', 'boolean'],
        ]);

        if (! array_key_exists('enabled', $data) && ! array_key_exists('show_wallet', $data)) {
            return $this->error('أرسل enabled أو show_wallet (true/false)', 422);
        }

        $enabled = array_key_exists('enabled', $data)
            ? (bool) $data['enabled']
            : (bool) $data['show_wallet'];

        $flag = AppFlag::setBool(self::FLAG_KEY, $enabled, $request->user()?->id);

        $show = (bool) ($flag->value['enabled'] ?? false);

        return $this->success([
            'show_wallet' => $show,
            'enabled' => $show,
            'updated_by' => $request->user()?->id,
            'updated_at' => now()->toIso8601String(),
        ], $show ? 'تم إظهار المحفظة في التطبيق' : 'تم إخفاء المحفظة من التطبيق');
    }

    /**
     * POST /api/admin/ios/wallet-visibility/toggle
     * Flips the current state without sending a body.
     */
    public function toggle(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return $this->error('غير مصرّح — صلاحيات الإدارة مطلوبة', 403);
        }

        $current = AppFlag::getBool(self::FLAG_KEY, true);
        $flag = AppFlag::setBool(self::FLAG_KEY, ! $current, $request->user()?->id);
        $show = (bool) ($flag->value['enabled'] ?? false);

        return $this->success([
            'show_wallet' => $show,
            'enabled' => $show,
            'previous' => $current,
            'updated_by' => $request->user()?->id,
            'updated_at' => now()->toIso8601String(),
        ], $show ? 'تم إظهار المحفظة في التطبيق' : 'تم إخفاء المحفظة من التطبيق');
    }

    protected function isAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && in_array($user->role, ['super_admin', 'admin'], true);
    }
}

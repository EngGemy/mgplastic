<?php

namespace App\Http\Controllers\Api\Ios;

use App\Http\Controllers\Controller;
use App\Models\AppFlag;
use Illuminate\Http\Request;

class IosWalletVisibilityController extends Controller
{
    // GET /api/ios/wallet-visibility
    public function show(Request $request)
    {
        $show = AppFlag::getBool('ios_wallet_enabled', true);

        return response()->json([
            'status'      => 200,
            'show_wallet' => $show,
        ]);
    }

    // PUT /api/admin/ios/wallet-visibility  (requires auth + ability)
    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required','boolean'],
        ]);

        $flag = AppFlag::setBool('ios_wallet_enabled', (bool) $data['enabled'], $request->user()?->id);

        return response()->json([
            'status'      => 200,
            'message'     => 'Wallet visibility updated',
            'show_wallet' => (bool) ($flag->value['enabled'] ?? false),
        ]);
    }
}

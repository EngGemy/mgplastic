<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Api\Concerns\HandlesStoreMedia;
use App\Http\Controllers\Controller;
use App\Models\PlumberStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlumberStoreMediaController extends Controller
{
    use HandlesStoreMedia;

    protected function resolveStoreMediaOwner(Request $request): Model
    {
        $user = Auth::user();
        if (! $user || ! $user->isVendor()) {
            abort(response()->json(['status' => false, 'message' => 'للبائعين فقط'], 403));
        }

        $storeId = (int) $request->route('id');

        $store = PlumberStore::query()->whereKey($storeId)->first();
        if (! $store) {
            abort(response()->json(['status' => false, 'message' => 'المتجر غير موجود'], 404));
        }

        if ((int) $store->vendor_id !== (int) $user->id) {
            abort(response()->json(['status' => false, 'message' => 'لا تملك هذا المتجر'], 403));
        }

        return $store;
    }
}

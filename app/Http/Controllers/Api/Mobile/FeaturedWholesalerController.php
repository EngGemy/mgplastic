<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class FeaturedWholesalerController extends Controller
{
    use ApiResponds;

    /** GET /api/v1/mobile/featured-wholesalers — أهم الموزعين (logo + brand). */
    public function index(): JsonResponse
    {
        $items = User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_featured_wholesaler', true)
            ->where('is_approved', true)
            ->where('is_active', true)
            ->orderBy('featured_sort')
            ->orderBy('brand_name')
            ->orderBy('name')
            ->get(['id', 'name', 'brand_name', 'brand_logo', 'profile_photo', 'phone', 'city_id', 'network_code', 'featured_sort'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->brand_name ?: $u->name,
                'brand_name' => $u->brand_name,
                'phone' => $u->phone,
                'network_code' => $u->network_code,
                'logo' => $u->storeBrandImageUrl(),
                'logo_url' => $u->storeBrandImageUrl(),
                'featured_sort' => (int) ($u->featured_sort ?? 0),
            ])
            ->values();

        return $this->success(['items' => $items], 'أهم الموزعين');
    }
}

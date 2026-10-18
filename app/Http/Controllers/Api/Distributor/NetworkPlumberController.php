<?php

namespace App\Http\Controllers\Api\Distributor;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use App\Models\User;
use App\Services\PlumberRetailLinkService;
use App\Services\RetailNetworkLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Wholesaler manages plumbers through linked retail traders
 * (domain: plumber.parent = retail_trader, not wholesale).
 */
class NetworkPlumberController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $wholesaler = $request->user();
        $retailIds = app(RetailNetworkLinkService::class)->linkedRetailerIds($wholesaler);

        if ($request->filled('retail_trader_id')) {
            $retailId = (int) $request->integer('retail_trader_id');
            if (! in_array($retailId, $retailIds, true)) {
                return $this->error('هذا التاجر القطاعي غير مرتبط بشبكتك', 403);
            }
            $retailIds = [$retailId];
        }

        if ($retailIds === []) {
            return $this->success([
                'items' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int) $request->integer('per_page', 50),
                    'total' => 0,
                ],
            ]);
        }

        $query = User::query()
            ->where('role', 'plumber')
            ->where('is_active', true)
            ->whereIn('parent_distributor_id', $retailIds)
            ->with([
                'country',
                'city',
                'parentDistributor:id,name,brand_name,role',
                'walletAccounts' => fn ($q) => $q->where('currency', 'LYD'),
            ])
            ->orderBy('name');

        $term = trim((string) $request->query('search', $request->query('q', '')));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('network_code', 'like', $like);
            });
        }

        $page = $query->paginate(max(1, min(100, $request->integer('per_page', 50))));

        return $this->success([
            'items' => UserProfileResource::collection($page->items()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Link an existing plumber under one of the wholesaler's linked retail shops.
     *
     * Body: { "retail_trader_id": 12, "code"|"phone"|"network_code": "…" }
     */
    public function link(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'retail_trader_id' => ['required', 'integer', 'exists:users,id'],
            'network_code' => ['required_without_all:code,phone', 'nullable', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        if ($v->fails()) {
            return $this->error('اختر تاجر قطاعي من شبكتك وأدخل رقم السباك الموحّد أو الهاتف', 422, $v->errors());
        }

        $retail = $this->assertLinkedRetail($request->user(), (int) $request->integer('retail_trader_id'));
        if ($retail instanceof JsonResponse) {
            return $retail;
        }

        $needle = trim((string) (
            $request->input('network_code')
            ?: $request->input('code')
            ?: $request->input('phone')
        ));

        try {
            $result = app(PlumberRetailLinkService::class)->linkByCode($retail, $needle);
            $result['plumber']->load([
                'country',
                'city',
                'parentDistributor:id,name,brand_name,role',
                'walletAccounts' => fn ($q) => $q->where('currency', 'LYD'),
            ]);

            return $this->success([
                'created_link' => $result['created_link'],
                'transferred' => (bool) ($result['transferred'] ?? false),
                'retail_trader_id' => $retail->id,
                'plumber' => new UserProfileResource($result['plumber']),
            ], $result['message'], $result['created_link'] ? 201 : 200);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Register a new plumber under a linked retail trader.
     */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'retail_trader_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات السباك غير صالحة', 422, $v->errors());
        }

        $retail = $this->assertLinkedRetail($request->user(), (int) $request->integer('retail_trader_id'));
        if ($retail instanceof JsonResponse) {
            return $retail;
        }

        $phone = trim((string) $request->input('phone'));
        if (User::query()->where('phone', $phone)->exists()) {
            return $this->error('رقم الهاتف مستخدم مسبقاً — استخدم الربط بالرقم الموحّد إن كان سباكاً مسجّلاً', 422);
        }

        $plumber = new User();
        $plumber->fill([
            'name' => $request->input('name'),
            'phone' => $phone,
            'password' => Hash::make($request->input('password') ?: str()->random(12)),
            'role' => 'plumber',
            'country_id' => $request->input('country_id'),
            'city_id' => $request->input('city_id'),
            'parent_distributor_id' => $retail->id,
            'is_active' => true,
            'is_approved' => true,
            'is_phone_verified' => true,
            'approved_at' => now(),
        ]);
        $plumber->save();
        app(\App\Services\NetworkCodeService::class)->ensure($plumber);

        $plumber->load([
            'country',
            'city',
            'parentDistributor:id,name,brand_name,role',
            'walletAccounts' => fn ($q) => $q->where('currency', 'LYD'),
        ]);

        return $this->created([
            'retail_trader_id' => $retail->id,
            'plumber' => new UserProfileResource($plumber),
        ], 'تم تسجيل السباك وإضافته تحت التاجر القطاعي في شبكتك');
    }

    public function show(Request $request, User $plumber): JsonResponse
    {
        if ($plumber->role !== 'plumber' || ! $plumber->is_active) {
            return $this->error('السباك غير موجود', 404);
        }

        $retailIds = app(RetailNetworkLinkService::class)->linkedRetailerIds($request->user());
        if (! in_array((int) $plumber->parent_distributor_id, $retailIds, true)) {
            return $this->error('السباك ليس ضمن شبكة تجّارك القطاعيين', 403);
        }

        $plumber->load([
            'country',
            'city',
            'parentDistributor:id,name,brand_name,role',
            'walletAccounts' => fn ($q) => $q->where('currency', 'LYD'),
        ]);

        return $this->success(new UserProfileResource($plumber));
    }

    protected function assertLinkedRetail(User $wholesaler, int $retailId): User|JsonResponse
    {
        $retail = User::query()->find($retailId);
        if (! $retail || ! $retail->isRetailTrader()) {
            return $this->error('التاجر القطاعي غير موجود', 404);
        }

        if (! app(RetailNetworkLinkService::class)->isLinked($wholesaler, $retail)) {
            return $this->error('التاجر القطاعي غير مرتبط بشبكتك — أضفه أولاً', 403);
        }

        return $retail;
    }
}

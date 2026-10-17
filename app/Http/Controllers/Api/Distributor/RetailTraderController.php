<?php

namespace App\Http\Controllers\Api\Distributor;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use App\Models\User;
use App\Services\RetailNetworkLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RetailTraderController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $items = app(RetailNetworkLinkService::class)
            ->linkedRetailersFor($request->user())
            ->load(['country', 'city']);

        $term = trim((string) $request->query('search', $request->query('q', '')));
        if ($term !== '') {
            $like = mb_strtolower($term);
            $items = $items->filter(function (User $u) use ($like) {
                $hay = mb_strtolower(implode(' ', array_filter([
                    $u->name,
                    $u->brand_name,
                    $u->phone,
                    $u->network_code,
                ])));

                return str_contains($hay, $like);
            })->values();
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 50)));
        $slice = $items->forPage($page, $perPage)->values();

        return $this->success([
            'items' => UserProfileResource::collection($slice),
            'pagination' => [
                'current_page' => $page,
                'last_page' => (int) max(1, (int) ceil($items->count() / $perPage)),
                'per_page' => $perPage,
                'total' => $items->count(),
            ],
        ]);
    }

    public function show(Request $request, User $retailTrader): JsonResponse
    {
        if ($retailTrader->role !== 'retail_trader'
            || ! app(RetailNetworkLinkService::class)->isLinked($request->user(), $retailTrader)) {
            return $this->error('التاجر القطاعي غير موجود في شبكتك', 403);
        }

        $retailTrader->load(['country', 'city']);

        return $this->success(new UserProfileResource($retailTrader));
    }

    /**
     * Link an already-registered retail trader by network_code or phone.
     *
     * POST body: { "network_code": "MG-…" } or { "phone": "09…" }
     */
    public function link(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'network_code' => ['required_without:phone', 'nullable', 'string', 'max:40'],
            'phone' => ['required_without:network_code', 'nullable', 'string', 'max:40'],
            'code' => ['nullable', 'string', 'max:40'], // alias
        ]);

        if ($v->fails()) {
            return $this->error('أدخل الرقم الموحّد أو رقم الهاتف للتاجر القطاعي', 422, $v->errors());
        }

        $needle = trim((string) (
            $request->input('network_code')
            ?: $request->input('code')
            ?: $request->input('phone')
        ));

        try {
            $result = app(RetailNetworkLinkService::class)->linkByCode(
                wholesaler: $request->user(),
                codeOrPhone: $needle,
                linkedBy: $request->user(),
            );

            $result['retail']->load(['country', 'city']);

            return $this->success([
                'created_link' => $result['created_link'],
                'retail_trader' => new UserProfileResource($result['retail']),
            ], $result['message'], $result['created_link'] ? 201 : 200);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Register a new retail trader and attach to this wholesaler network.
     */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
            'brand_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'store_description' => ['nullable', 'string', 'max:2000'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        if ($v->fails()) {
            return $this->error('بيانات التاجر القطاعي غير صالحة', 422, $v->errors());
        }

        try {
            $retail = app(RetailNetworkLinkService::class)->registerAndLink(
                $request->user(),
                $v->validated(),
                $request->user(),
            );
            $retail->load(['country', 'city']);

            return $this->created([
                'retail_trader' => new UserProfileResource($retail),
            ], 'تم تسجيل التاجر القطاعي وإضافته لشبكتك');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function unlink(Request $request, User $retailTrader): JsonResponse
    {
        $links = app(RetailNetworkLinkService::class);

        if ($retailTrader->role !== 'retail_trader' || ! $links->isLinked($request->user(), $retailTrader)) {
            return $this->error('التاجر القطاعي غير موجود في شبكتك', 403);
        }

        $links->detach($request->user(), $retailTrader);

        return $this->success(null, 'تم فك ارتباط التاجر القطاعي من شبكتك');
    }
}

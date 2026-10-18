<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use App\Models\User;
use App\Services\RetailNetworkLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Retail trader manages multiple wholesale suppliers (many-to-many).
 */
class WholesalerController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected RetailNetworkLinkService $links,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->links->linkedWholesalersFor($request->user())
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
                'last_page' => (int) max(1, (int) ceil(max(1, $items->count()) / $perPage)),
                'per_page' => $perPage,
                'total' => $items->count(),
            ],
        ], 'موزّعو الجملة في شبكتك');
    }

    public function link(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'network_code' => ['required_without_all:code,phone', 'nullable', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        if ($v->fails()) {
            return $this->error('أدخل الرقم الموحّد أو رقم الهاتف لموزّع الجملة', 422, $v->errors());
        }

        $needle = trim((string) (
            $request->input('network_code')
            ?: $request->input('code')
            ?: $request->input('phone')
        ));

        try {
            $result = $this->links->linkWholesalerByCode($request->user(), $needle);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $result['wholesaler']->load(['country', 'city']);

        return $this->success([
            'created_link' => $result['created_link'],
            'wholesaler' => new UserProfileResource($result['wholesaler']),
        ], $result['message'], $result['created_link'] ? 201 : 200);
    }

    public function unlink(Request $request, User $wholesaler): JsonResponse
    {
        try {
            $result = $this->links->unlinkWholesaler($request->user(), $wholesaler);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'wholesaler' => new UserProfileResource($result['wholesaler']),
        ], $result['message']);
    }

    public function show(Request $request, User $wholesaler): JsonResponse
    {
        if (! $wholesaler->isWholesaleDistributor()
            || ! $this->links->isLinked($wholesaler, $request->user())) {
            return $this->error('الموزّع غير موجود في شبكتك', 404);
        }

        $wholesaler->load(['country', 'city']);

        return $this->success(new UserProfileResource($wholesaler));
    }
}

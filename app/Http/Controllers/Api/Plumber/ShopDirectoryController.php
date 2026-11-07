<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\NetworkStoreResource;
use App\Services\NetworkStoreDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plumber shop directory — retail traders only, nearest / search / favorites.
 * Alias of GET /network-stores with plumber-focused messaging.
 */
class ShopDirectoryController extends Controller
{
    use ApiResponds;

    public function __construct(
        protected NetworkStoreDirectoryService $directory,
    ) {}

    /** GET /api/v1/mobile/plumber/shops */
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $result = $this->directory->paginate($request, $viewer);
        $paginator = $result['paginator'];

        return response()->json([
            'status' => true,
            'message' => 'محلات القطاعي القريبة منك',
            'data' => NetworkStoreResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'listed_role' => $result['listed_role'],
                'viewer_role' => $viewer?->role,
                'sort' => $result['sort'],
                'origin' => $result['origin'],
                'filters' => [
                    'sort' => NetworkStoreDirectoryService::SORTS,
                    'q' => trim((string) ($request->input('q') ?? $request->input('search') ?? '')) ?: null,
                    'city_id' => $request->filled('city_id') ? (int) $request->input('city_id') : null,
                    'country_id' => $request->filled('country_id') ? (int) $request->input('country_id') : null,
                    'favorites_only' => $request->boolean('favorites_only'),
                    'has_location' => $request->boolean('has_location'),
                ],
            ],
        ]);
    }
}

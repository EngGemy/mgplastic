<?php

namespace App\Http\Controllers\Api\Plumber;

use App\Http\Controllers\Controller;
use App\Http\Resources\Plumber\PlumberListResource;
use App\Http\Resources\Plumber\PlumberShowResource;
use App\Models\User;
use Illuminate\Http\Request;

class PlumberPublicController extends Controller
{
    /**
     * GET /api/v1/plumbers
     * Query params: search, country_id, city_id, per_page (default 15), page
     */
    public function index(Request $request)
    {
        $query = User::query()
            ->where('role', User::ROLE_PLUMBER)
            ->approved()
            ->active()
            ->with([
                'city:id,country_id,name_en,name_ar',
                'country:id,name_en,name_ar',
            ])
            ->select([
                'id',
                'name',
                'profile_photo',
                'city_id',
                'country_id',
                'short_description',
                'latitude',
                'longitude',
            ]);

        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$s}%");
        }

        if ($request->filled('country_id')) {
            $query->where('country_id', (int) $request->input('country_id'));
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', (int) $request->input('city_id'));
        }

        $perPage   = max(1, min(100, (int) $request->input('per_page', 15)));
        $paginated = $query->latest('id')->paginate($perPage)->withQueryString();

        return response()->json([
            'status'  => true,
            'message' => 'Plumbers list',
            'data'    => PlumberListResource::collection($paginated->items()),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/plumbers/{id}
     *
     * Returns:
     * - plumber base data (from PlumberShowResource)
     * - all work media (images + videos) with unified shape:
     *   [
     *     id, type, path, url, thumbnail, created_at
     *   ]
     */
    public function show(Request $request, $id)
    {
        $plumber = User::query()
            ->where('role', User::ROLE_PLUMBER)
            ->approved()
            ->active()
            ->with([
                'city:id,country_id,name_en,name_ar',
                'country:id,name_en,name_ar',
                'socialLinks',
                'workPhotos' => function ($q) {
                    $q->latest();
                },
            ])
            ->find($id);

        if (! $plumber) {
            return response()->json([
                'status'  => false,
                'message' => 'Plumber not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Plumber details',
            'data' => (new PlumberShowResource($plumber))->toArray($request),
        ]);
    }
}

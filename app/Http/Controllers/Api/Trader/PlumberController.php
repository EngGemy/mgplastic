<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlumberController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $plumbers = User::query()
            ->where('role', 'plumber')
            ->where('parent_distributor_id', $request->user()->id)
            ->where('is_active', true)
            ->with(['country', 'city'])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return $this->success([
            'items' => UserProfileResource::collection($plumbers->items()),
            'pagination' => [
                'current_page' => $plumbers->currentPage(),
                'last_page' => $plumbers->lastPage(),
                'per_page' => $plumbers->perPage(),
                'total' => $plumbers->total(),
            ],
        ]);
    }

    public function show(Request $request, User $plumber): JsonResponse
    {
        if ($plumber->role !== 'plumber'
            || (int) $plumber->parent_distributor_id !== (int) $request->user()->id) {
            return $this->error('غير مصرح', 403);
        }

        $plumber->load(['country', 'city']);

        return $this->success(new UserProfileResource($plumber));
    }
}

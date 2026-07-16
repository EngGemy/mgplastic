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
        $query = User::query()
            ->where('role', 'plumber')
            ->where('is_active', true)
            ->with(['country', 'city'])
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

        $plumbers = $query->paginate($request->integer('per_page', 50));

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
        if ($plumber->role !== 'plumber' || ! $plumber->is_active) {
            return $this->error('غير مصرح', 403);
        }

        $plumber->load(['country', 'city']);

        return $this->success(new UserProfileResource($plumber));
    }
}

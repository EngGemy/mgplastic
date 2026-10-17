<?php

namespace App\Http\Controllers\Api\Trader;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\UserProfileResource;
use App\Models\User;
use App\Services\PlumberRetailLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlumberController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('role', 'plumber')
            ->where('is_active', true)
            ->where('parent_distributor_id', $request->user()->id)
            ->with([
                'country',
                'city',
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

    public function link(Request $request, PlumberRetailLinkService $links): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'network_code' => ['required_without_all:code,phone', 'nullable', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        if ($v->fails()) {
            return $this->error('أدخل الرقم الموحّد أو رقم الهاتف', 422, $v->errors());
        }

        $needle = trim((string) (
            $request->input('network_code')
            ?: $request->input('code')
            ?: $request->input('phone')
        ));

        try {
            $result = $links->linkByCode($request->user(), $needle);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $result['plumber']->load([
            'country',
            'city',
            'walletAccounts' => fn ($q) => $q->where('currency', 'LYD'),
        ]);

        return $this->success([
            'created_link' => $result['created_link'],
            'plumber' => new UserProfileResource($result['plumber']),
        ], $result['message'], $result['created_link'] ? 201 : 200);
    }

    public function show(Request $request, User $plumber): JsonResponse
    {
        if ($plumber->role !== 'plumber' || ! $plumber->is_active || (int) $plumber->parent_distributor_id !== (int) $request->user()->id) {
            return $this->error('السباك غير موجود في شبكتك', 404);
        }

        $plumber->load([
            'country',
            'city',
            'walletAccounts' => fn ($q) => $q->where('currency', 'LYD'),
        ]);

        return $this->success(new UserProfileResource($plumber));
    }
}

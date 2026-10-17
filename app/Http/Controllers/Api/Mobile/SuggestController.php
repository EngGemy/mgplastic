<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RetailNetworkLinkService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fast typeahead suggestions for linking / picking users & stores.
 *
 * GET /api/v1/mobile/suggest?q=مفتاح&type=plumber&limit=8
 */
class SuggestController extends Controller
{
    use ApiResponds;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', $request->query('search', '')));
        $type = strtolower(trim((string) $request->query('type', 'auto')));
        $limit = max(1, min(20, $request->integer('limit', 8)));

        if (mb_strlen($q) < 1) {
            return $this->success(['items' => [], 'q' => $q, 'type' => $type], 'اكتب حرفاً على الأقل');
        }

        $actor = $request->user();
        $type = $this->resolveType($type, $actor);

        $items = match ($type) {
            'plumber' => $this->suggestPlumbers($q, $limit, $actor),
            'retail_trader' => $this->suggestRetailTraders($q, $limit, $actor),
            'wholesale_distributor' => $this->suggestWholesalers($q, $limit, $actor),
            'store' => $this->suggestStores($q, $limit, $actor),
            default => [],
        };

        return $this->success([
            'q' => $q,
            'type' => $type,
            'items' => $items,
        ], 'اقتراحات سريعة');
    }

    protected function resolveType(string $type, User $actor): string
    {
        if (in_array($type, ['plumber', 'retail_trader', 'wholesale_distributor', 'store'], true)) {
            return $type;
        }

        // auto: best default for the caller's role
        return match ($actor->role) {
            'retail_trader' => 'plumber',
            'wholesale_distributor' => 'retail_trader',
            'plumber' => 'store',
            default => 'store',
        };
    }

    /** @return list<array<string, mixed>> */
    protected function suggestPlumbers(string $q, int $limit, User $actor): array
    {
        $query = User::query()
            ->where('role', 'plumber')
            ->where('is_active', true)
            ->where($this->matchClause($q));

        // Retail trader: prefer unlinked + own network (for link UX)
        if ($actor->isRetailTrader()) {
            $query->where(function (Builder $b) use ($actor) {
                $b->whereNull('parent_distributor_id')
                    ->orWhere('parent_distributor_id', $actor->id);
            });
        }

        // Wholesaler: plumbers under linked retail shops
        if ($actor->isWholesaleDistributor()) {
            $retailIds = app(RetailNetworkLinkService::class)->linkedRetailerIds($actor);
            if ($retailIds === []) {
                return [];
            }
            $query->where(function (Builder $b) use ($retailIds) {
                $b->whereNull('parent_distributor_id')
                    ->orWhereIn('parent_distributor_id', $retailIds);
            });
        }

        return $query->orderBy('name')->limit($limit)->get()
            ->map(fn (User $u) => $this->mapUser($u, 'plumber'))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function suggestRetailTraders(string $q, int $limit, User $actor): array
    {
        $query = User::query()
            ->where('role', 'retail_trader')
            ->where('is_active', true)
            ->where('is_approved', true)
            ->where($this->matchClause($q));

        if ($actor->isWholesaleDistributor()) {
            // Prefer not-yet-linked for "add" UX, but still show linked ones
            // (app can filter by linked flag)
        }

        return $query->orderByRaw('COALESCE(brand_name, name)')->limit($limit)->get()
            ->map(function (User $u) use ($actor) {
                $row = $this->mapUser($u, 'retail_trader');
                if ($actor->isWholesaleDistributor()) {
                    $row['linked'] = app(RetailNetworkLinkService::class)->isLinked($actor, $u);
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function suggestWholesalers(string $q, int $limit, User $actor): array
    {
        return User::query()
            ->where('role', 'wholesale_distributor')
            ->where('is_active', true)
            ->where('is_approved', true)
            ->where($this->matchClause($q))
            ->orderByRaw('COALESCE(brand_name, name)')
            ->limit($limit)
            ->get()
            ->map(fn (User $u) => $this->mapUser($u, 'wholesale_distributor'))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function suggestStores(string $q, int $limit, User $actor): array
    {
        $roles = match ($actor->role) {
            'plumber' => ['retail_trader'],
            'retail_trader' => ['wholesale_distributor'],
            default => ['retail_trader', 'wholesale_distributor'],
        };

        return User::query()
            ->whereIn('role', $roles)
            ->where('is_active', true)
            ->where('is_approved', true)
            ->where($this->matchClause($q))
            ->orderByRaw('COALESCE(brand_name, name)')
            ->limit($limit)
            ->get()
            ->map(fn (User $u) => $this->mapUser($u, $u->role === 'wholesale_distributor' ? 'wholesale_distributor' : 'retail_trader'))
            ->values()
            ->all();
    }

    /** @return \Closure(Builder): void */
    protected function matchClause(string $q): \Closure
    {
        $like = '%'.$q.'%';
        $normalized = mb_strtoupper(preg_replace('/\s+/', '', $q) ?? $q);

        return function (Builder $query) use ($like, $normalized, $q) {
            $query->where(function (Builder $b) use ($like, $normalized, $q) {
                $b->where('name', 'like', $like)
                    ->orWhere('brand_name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('network_code', 'like', $like);

                if ($normalized !== '' && $normalized !== mb_strtoupper($q)) {
                    $b->orWhere('network_code', 'like', '%'.$normalized.'%');
                }
            });
        };
    }

    /** @return array<string, mixed> */
    protected function mapUser(User $user, string $type): array
    {
        return [
            'id' => (int) $user->id,
            'type' => $type,
            'role' => $user->role,
            'name' => $user->name,
            'brand_name' => $user->brand_name,
            'display_name' => $user->brand_name ?: $user->name,
            'phone' => $user->phone,
            'network_code' => $user->network_code,
            'profile_photo' => $user->profile_photo_url ?? null,
            'subtitle' => collect([
                $user->network_code,
                $user->phone,
            ])->filter()->implode(' · '),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

class NetworkStoreDirectoryService
{
    public const SORTS = ['nearest', 'newest', 'name'];

    /**
     * Plumber/guest → retail shops. Retail trader → wholesale shops.
     */
    public function directoryRoleFor(?User $viewer): string
    {
        if ($viewer?->isRetailTrader()) {
            return 'wholesale_distributor';
        }

        return 'retail_trader';
    }

    /**
     * @return array{latitude: ?float, longitude: ?float, source: string}
     */
    public function resolveOrigin(Request $request, ?User $viewer): array
    {
        $lat = $request->input('lat', $request->input('latitude'));
        $lng = $request->input('lng', $request->input('longitude'));

        if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
            return [
                'latitude' => (float) $lat,
                'longitude' => (float) $lng,
                'source' => 'query',
            ];
        }

        if ($viewer && $viewer->hasMapLocation()) {
            return [
                'latitude' => (float) $viewer->latitude,
                'longitude' => (float) $viewer->longitude,
                'source' => 'profile',
            ];
        }

        return [
            'latitude' => null,
            'longitude' => null,
            'source' => 'none',
        ];
    }

    public function resolveSort(Request $request, array $origin): string
    {
        $sort = strtolower(trim((string) $request->input('sort', '')));

        if ($sort === '' || $sort === 'distance') {
            $sort = $origin['source'] !== 'none' ? 'nearest' : 'newest';
        }

        if ($sort === 'nearest' && $origin['source'] === 'none') {
            return 'newest';
        }

        return in_array($sort, self::SORTS, true) ? $sort : 'newest';
    }

    /**
     * @return array{paginator: LengthAwarePaginator, listed_role: string, sort: string, origin: array{latitude: ?float, longitude: ?float, source: string}, favorite_ids: list<int>}
     */
    public function paginate(Request $request, ?User $viewer): array
    {
        $listedRole = $this->directoryRoleFor($viewer);
        $origin = $this->resolveOrigin($request, $viewer);
        $sort = $this->resolveSort($request, $origin);
        $perPage = max(1, min((int) $request->input('per_page', 15), 50));
        $favoriteIds = $this->favoriteStoreIds($viewer);

        $query = User::query()
            ->where('users.role', $listedRole)
            ->where('users.is_approved', true)
            ->where('users.is_active', true)
            ->with(['city', 'country', 'storeMedia', 'socialLinks']);

        if ($listedRole === 'wholesale_distributor') {
            $query->where(function ($q) {
                $q->whereNull('users.is_central_warehouse')
                    ->orWhere('users.is_central_warehouse', false);
            });
        }

        if ($request->filled('city_id')) {
            $query->where('users.city_id', (int) $request->input('city_id'));
        }

        if ($request->filled('country_id')) {
            $query->where('users.country_id', (int) $request->input('country_id'));
        }

        if ($request->boolean('has_location')) {
            $query->whereNotNull('users.latitude')->whereNotNull('users.longitude');
        }

        $search = trim((string) ($request->input('q') ?? $request->input('search') ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('users.name', 'like', $like)
                    ->orWhere('users.brand_name', 'like', $like)
                    ->orWhere('users.phone', 'like', $like)
                    ->orWhere('users.network_code', 'like', $like)
                    ->orWhere('users.address', 'like', $like);
            });
        }

        if ($request->boolean('favorites_only')) {
            if ($favoriteIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('users.id', $favoriteIds);
            }
        }

        if ($sort === 'nearest' && $origin['latitude'] !== null && $origin['longitude'] !== null) {
            $driver = $query->getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                // PHPUnit sqlite has no acos() — sort in PHP. MySQL uses SQL Haversine.
                $paginator = $this->paginateNearestInPhp(
                    $query,
                    (float) $origin['latitude'],
                    (float) $origin['longitude'],
                    $perPage,
                );
            } else {
                $this->applyNearestOrder($query, (float) $origin['latitude'], (float) $origin['longitude']);
                $paginator = $query->paginate($perPage);
            }
        } elseif ($sort === 'name') {
            $query->orderByRaw('COALESCE(users.brand_name, users.name)')->orderBy('users.id');
            $paginator = $query->paginate($perPage);
        } else {
            $query->orderByDesc('users.id');
            $paginator = $query->paginate($perPage);
        }

        $this->annotatePage($paginator->getCollection(), $origin, $favoriteIds);

        return [
            'paginator' => $paginator,
            'listed_role' => $listedRole,
            'sort' => $sort,
            'origin' => $origin,
            'favorite_ids' => $favoriteIds,
        ];
    }

    protected function paginateNearestInPhp(Builder $query, float $lat, float $lng, int $perPage): LengthAwarePaginator
    {
        $sorted = $query->get()->sortBy(function (User $store) use ($lat, $lng) {
            if (! $store->hasMapLocation()) {
                return PHP_FLOAT_MAX;
            }

            return $this->haversineKm($lat, $lng, (float) $store->latitude, (float) $store->longitude);
        })->values();

        $page = Paginator::resolveCurrentPage();
        $slice = $sorted->forPage($page, $perPage)->values();

        return new Paginator($slice, $sorted->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /**
     * @param  list<int>  $favoriteIds
     * @param  array{latitude: ?float, longitude: ?float, source: string}  $origin
     */
    public function annotatePage(Collection $stores, array $origin, array $favoriteIds): void
    {
        $favoriteLookup = array_fill_keys($favoriteIds, true);

        foreach ($stores as $store) {
            /** @var User $store */
            $store->setAttribute('is_favorite', isset($favoriteLookup[$store->id]));

            $distance = null;
            if ($origin['latitude'] !== null && $origin['longitude'] !== null && $store->hasMapLocation()) {
                $distance = round($this->haversineKm(
                    (float) $origin['latitude'],
                    (float) $origin['longitude'],
                    (float) $store->latitude,
                    (float) $store->longitude,
                ), 2);
            } elseif (isset($store->distance_km) && is_numeric($store->distance_km)) {
                $distance = round((float) $store->distance_km, 2);
            }

            $store->setAttribute('distance_km', $distance);
        }
    }

    /**
     * @return list<int>
     */
    public function favoriteStoreIds(?User $viewer): array
    {
        if (! $viewer) {
            return [];
        }

        return $viewer->favoriteStores()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    public function assertFavoriteableStore(User $viewer, User $store): void
    {
        $expected = $this->directoryRoleFor($viewer);

        if ($store->role !== $expected) {
            throw new \DomainException('لا يمكن إضافة هذا المحل للمفضلة من حسابك');
        }

        if (! $store->is_approved || ! $store->is_active) {
            throw new \DomainException('المحل غير متاح حالياً');
        }

        if ($store->isCentralWarehouse()) {
            throw new \DomainException('لا يمكن إضافة مخزن الدعوة للمفضلة');
        }
    }

    protected function applyNearestOrder(Builder $query, float $lat, float $lng): void
    {
        // Haversine km — clamp acos arg for float noise (MySQL LEAST/GREATEST, SQLite min/max).
        $inner = 'cos(? * pi() / 180) * cos(users.latitude * pi() / 180)
            * cos((users.longitude - ?) * pi() / 180)
            + sin(? * pi() / 180) * sin(users.latitude * pi() / 180)';

        $driver = $query->getConnection()->getDriverName();
        $clamped = $driver === 'sqlite'
            ? "min(1.0, max(-1.0, {$inner}))"
            : "LEAST(1.0, GREATEST(-1.0, {$inner}))";

        $expression = "(6371 * acos({$clamped}))";

        $query->select('users.*')
            ->selectRaw("CASE WHEN users.latitude IS NULL OR users.longitude IS NULL THEN NULL ELSE {$expression} END AS distance_km", [$lat, $lng, $lat])
            ->orderByRaw('CASE WHEN users.latitude IS NULL OR users.longitude IS NULL THEN 1 ELSE 0 END')
            ->orderBy('distance_km')
            ->orderBy('users.id');
    }

    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}

<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\MapsNetworkUser;
use App\Models\User;
use App\Models\WalletAccount;

class ListPlumbersTool extends AbstractTool
{
    use MapsNetworkUser;

    public function name(): string
    {
        return 'list_plumbers';
    }

    public function description(): string
    {
        return 'List plumbers with phone, network_code, parent store, approval, and wallet points. Supports sorting by newest or highest points.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'q' => ['type' => 'string', 'description' => 'Search name, phone, network_code'],
            'parent_distributor_id' => ['type' => 'integer', 'description' => 'Retail trader / parent store id'],
            'is_approved' => ['type' => 'boolean'],
            'is_active' => ['type' => 'boolean'],
            'min_points' => ['type' => 'integer'],
            'order' => [
                'type' => 'string',
                'enum' => ['newest', 'oldest', 'points_desc', 'points_asc', 'name'],
                'description' => 'Default newest',
            ],
            'limit' => ['type' => 'integer', 'description' => 'Default 30, max 100'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 30)));
        $q = trim((string) ($arguments['q'] ?? ''));
        $order = (string) ($arguments['order'] ?? 'newest');

        $query = User::query()->where('role', User::ROLE_PLUMBER);

        if ($q !== '') {
            $query->where(function ($b) use ($q) {
                $b->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('network_code', 'like', "%{$q}%");
            });
        }

        if (! empty($arguments['parent_distributor_id'])) {
            $query->where('parent_distributor_id', (int) $arguments['parent_distributor_id']);
        }
        if (array_key_exists('is_approved', $arguments)) {
            $query->where('is_approved', (bool) $arguments['is_approved']);
        }
        if (array_key_exists('is_active', $arguments)) {
            $query->where('is_active', (bool) $arguments['is_active']);
        }

        $minPoints = isset($arguments['min_points']) ? (int) $arguments['min_points'] : null;
        if ($minPoints !== null) {
            $ownerIds = WalletAccount::query()
                ->where('currency', 'LYD')
                ->where('balance_points', '>=', $minPoints)
                ->pluck('owner_id');
            $query->whereIn('id', $ownerIds);
        }

        $total = (clone $query)->count();

        if (in_array($order, ['points_desc', 'points_asc'], true)) {
            $query->leftJoin('wallet_accounts', function ($join) {
                $join->on('wallet_accounts.owner_id', '=', 'users.id')
                    ->where('wallet_accounts.currency', '=', 'LYD');
            })
                ->select('users.*')
                ->orderBy('wallet_accounts.balance_points', $order === 'points_desc' ? 'desc' : 'asc')
                ->orderByDesc('users.id');
        } else {
            match ($order) {
                'oldest' => $query->orderBy('created_at')->orderBy('id'),
                'name' => $query->orderBy('name'),
                default => $query->orderByDesc('created_at')->orderByDesc('id'),
            };
        }

        $items = $query->with('parentDistributor:id,name,role,network_code,brand_name')
            ->limit($limit)
            ->get();

        return [
            'total_matching' => $total,
            'returned' => $items->count(),
            'latest_plumber' => $items->isNotEmpty() && $order === 'newest'
                ? $this->mapPlumber($items->first())
                : null,
            'plumbers' => $items->map(fn (User $u) => $this->mapPlumber($u))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function mapPlumber(User $user): array
    {
        $row = $this->mapUserBrief($user, true);
        $row['parent_store'] = $user->parentDistributor
            ? [
                'id' => $user->parentDistributor->id,
                'name' => $user->parentDistributor->brand_name ?: $user->parentDistributor->name,
                'role' => $user->parentDistributor->role,
                'network_code' => $user->parentDistributor->network_code,
            ]
            : null;

        return $row;
    }
}

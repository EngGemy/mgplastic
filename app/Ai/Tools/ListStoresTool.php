<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\MapsNetworkUser;
use App\Models\User;

class ListStoresTool extends AbstractTool
{
    use MapsNetworkUser;

    public function name(): string
    {
        return 'list_stores';
    }

    public function description(): string
    {
        return 'List network stores (wholesale distributors and/or retail traders) with approval status, network_code, and optional wallet points. Newest first.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'role' => [
                'type' => 'string',
                'enum' => ['wholesale_distributor', 'retail_trader', 'all'],
                'description' => 'Default all network stores',
            ],
            'q' => ['type' => 'string', 'description' => 'Search name, brand, phone, network_code'],
            'is_approved' => ['type' => 'boolean'],
            'is_active' => ['type' => 'boolean'],
            'with_wallet' => ['type' => 'boolean', 'description' => 'Include balance_points (default true)'],
            'limit' => ['type' => 'integer', 'description' => 'Default 30, max 100'],
            'order' => [
                'type' => 'string',
                'enum' => ['newest', 'oldest', 'name'],
            ],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 30)));
        $role = (string) ($arguments['role'] ?? 'all');
        $withWallet = array_key_exists('with_wallet', $arguments)
            ? (bool) $arguments['with_wallet']
            : true;
        $q = trim((string) ($arguments['q'] ?? ''));

        $roles = match ($role) {
            'wholesale_distributor' => ['wholesale_distributor'],
            'retail_trader' => ['retail_trader'],
            default => ['wholesale_distributor', 'retail_trader'],
        };

        $query = User::query()->whereIn('role', $roles);

        if ($q !== '') {
            $query->where(function ($b) use ($q) {
                $b->where('name', 'like', "%{$q}%")
                    ->orWhere('brand_name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('network_code', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        if (array_key_exists('is_approved', $arguments)) {
            $query->where('is_approved', (bool) $arguments['is_approved']);
        }
        if (array_key_exists('is_active', $arguments)) {
            $query->where('is_active', (bool) $arguments['is_active']);
        }

        $total = (clone $query)->count();

        match ((string) ($arguments['order'] ?? 'newest')) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $items = $query->limit($limit)->get();

        return [
            'total_matching' => $total,
            'returned' => $items->count(),
            'latest_store' => $items->first() ? $this->mapUserBrief($items->first(), $withWallet) : null,
            'stores' => $items->map(fn (User $u) => $this->mapUserBrief($u, $withWallet))->values()->all(),
        ];
    }
}

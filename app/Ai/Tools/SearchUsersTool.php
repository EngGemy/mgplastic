<?php

namespace App\Ai\Tools;

use App\Models\User;

class SearchUsersTool extends AbstractTool
{
    public function name(): string
    {
        return 'search_users';
    }

    public function description(): string
    {
        return 'Search users by name, phone, network_code, role, or approval/active status.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'q' => ['type' => 'string', 'description' => 'Name, phone, or network_code'],
            'role' => ['type' => 'string', 'description' => 'e.g. plumber, retail_trader, wholesale_distributor, admin'],
            'is_approved' => ['type' => 'boolean'],
            'is_active' => ['type' => 'boolean'],
            'limit' => ['type' => 'integer', 'description' => 'Default 20, max 50'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $q = trim((string) ($arguments['q'] ?? ''));

        $query = User::query()->latest('id');

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('network_code', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('brand_name', 'like', "%{$q}%");
            });
        }

        if (! empty($arguments['role'])) {
            $query->where('role', $arguments['role']);
        }
        if (array_key_exists('is_approved', $arguments)) {
            $query->where('is_approved', (bool) $arguments['is_approved']);
        }
        if (array_key_exists('is_active', $arguments)) {
            $query->where('is_active', (bool) $arguments['is_active']);
        }

        $items = $query->limit($limit)->get([
            'id', 'name', 'phone', 'email', 'role', 'network_code', 'brand_name',
            'is_approved', 'is_active', 'city_id', 'country_id', 'created_at',
        ]);

        return [
            'count' => $items->count(),
            'items' => $items->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'email' => $u->email,
                'role' => $u->role,
                'network_code' => $u->network_code,
                'brand_name' => $u->brand_name,
                'is_approved' => (bool) $u->is_approved,
                'is_active' => (bool) $u->is_active,
                'created_at' => optional($u->created_at)?->toIso8601String(),
            ])->all(),
        ];
    }
}

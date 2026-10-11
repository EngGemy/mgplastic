<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Models\WalletAccount;

class ListPointBalancesTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_point_balances';
    }

    public function description(): string
    {
        return 'List wallet point balances (plumbers/stores), highest first. Answers “مين عنده أعلى نقاط” and point leaderboards.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'role' => [
                'type' => 'string',
                'enum' => ['plumber', 'retail_trader', 'wholesale_distributor', 'all'],
                'description' => 'Default plumber',
            ],
            'min_points' => ['type' => 'integer', 'description' => 'Default 1'],
            'limit' => ['type' => 'integer', 'description' => 'Default 30, max 100'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 30)));
        $min = max(0, (int) ($arguments['min_points'] ?? 1));
        $role = (string) ($arguments['role'] ?? 'plumber');

        $query = WalletAccount::query()
            ->where('currency', 'LYD')
            ->where('balance_points', '>=', $min)
            ->with(['owner:id,name,brand_name,phone,role,network_code,is_approved,is_active'])
            ->orderByDesc('balance_points');

        if ($role !== 'all') {
            $query->whereHas('owner', fn ($q) => $q->where('role', $role));
        }

        $items = $query->limit($limit)->get();

        return [
            'returned' => $items->count(),
            'role_filter' => $role,
            'balances' => $items->map(function (WalletAccount $w) {
                /** @var User|null $owner */
                $owner = $w->owner;

                return [
                    'owner_id' => $w->owner_id,
                    'name' => $owner?->brand_name ?: $owner?->name,
                    'phone' => $owner?->phone,
                    'role' => $owner?->role,
                    'network_code' => $owner?->network_code,
                    'is_approved' => (bool) ($owner?->is_approved),
                    'is_active' => (bool) ($owner?->is_active),
                    'balance_points' => (int) $w->balance_points,
                    'balance_cents' => (int) $w->balance_cents,
                    'balance_lyd' => round(((int) $w->balance_cents) / 100, 2),
                ];
            })->values()->all(),
        ];
    }
}

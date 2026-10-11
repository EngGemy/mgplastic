<?php

namespace App\Ai\Tools;

use App\Models\User;

class ListPendingApprovalsTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_pending_approvals';
    }

    public function description(): string
    {
        return 'List users/stores waiting for admin approval (is_approved=false).';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'role' => [
                'type' => 'string',
                'description' => 'Optional role filter: wholesale_distributor, retail_trader, plumber, vendor',
            ],
            'limit' => ['type' => 'integer'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 30)));
        $query = User::query()
            ->where('is_approved', false)
            ->whereIn('role', ['wholesale_distributor', 'retail_trader', 'plumber', 'vendor'])
            ->latest('id');

        if (! empty($arguments['role'])) {
            $query->where('role', $arguments['role']);
        }

        $items = $query->limit($limit)->get([
            'id', 'name', 'phone', 'role', 'brand_name', 'network_code', 'is_active', 'created_at',
        ]);

        return [
            'count' => $items->count(),
            'items' => $items->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'role' => $u->role,
                'brand_name' => $u->brand_name,
                'network_code' => $u->network_code,
                'is_active' => (bool) $u->is_active,
                'created_at' => optional($u->created_at)?->toIso8601String(),
            ])->all(),
        ];
    }
}

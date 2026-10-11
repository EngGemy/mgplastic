<?php

namespace App\Ai\Tools;

use App\Models\InvoiceDistribution;

class SearchDistributionsTool extends AbstractTool
{
    public function name(): string
    {
        return 'search_distributions';
    }

    public function description(): string
    {
        return 'Search invoice distributions by status, from/to user, invoice id, or tier.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'status' => ['type' => 'string'],
            'invoice_id' => ['type' => 'integer'],
            'from_user_id' => ['type' => 'integer'],
            'to_user_id' => ['type' => 'integer'],
            'tier' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $query = InvoiceDistribution::query()->latest('id');

        foreach (['status', 'invoice_id', 'from_user_id', 'to_user_id', 'tier'] as $field) {
            if (isset($arguments[$field]) && $arguments[$field] !== '' && $arguments[$field] !== null) {
                $query->where($field, $arguments[$field]);
            }
        }

        $items = $query->with(['fromUser:id,name,role', 'toUser:id,name,role'])->limit($limit)->get();

        return [
            'count' => $items->count(),
            'items' => $items->map(fn (InvoiceDistribution $d) => [
                'id' => $d->id,
                'invoice_id' => $d->invoice_id,
                'status' => $d->status,
                'tier' => $d->tier,
                'from_user' => $d->fromUser ? ['id' => $d->fromUser->id, 'name' => $d->fromUser->name, 'role' => $d->fromUser->role] : null,
                'to_user' => $d->toUser ? ['id' => $d->toUser->id, 'name' => $d->toUser->name, 'role' => $d->toUser->role] : null,
                'confirmed_at' => optional($d->confirmed_at)?->toIso8601String(),
                'points_awarded_at' => optional($d->points_awarded_at)?->toIso8601String(),
                'created_at' => optional($d->created_at)?->toIso8601String(),
            ])->all(),
        ];
    }
}

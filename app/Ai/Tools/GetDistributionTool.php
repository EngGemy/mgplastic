<?php

namespace App\Ai\Tools;

use App\Models\InvoiceDistribution;
use InvalidArgumentException;

class GetDistributionTool extends AbstractTool
{
    public function name(): string
    {
        return 'get_distribution';
    }

    public function description(): string
    {
        return 'Get one distribution with items and related users.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'distribution_id' => ['type' => 'integer'],
        ], ['distribution_id']);
    }

    public function handle(array $arguments): array
    {
        /** @var InvoiceDistribution|null $d */
        $d = InvoiceDistribution::query()
            ->with(['items', 'fromUser:id,name,phone,role', 'toUser:id,name,phone,role', 'invoice:id,number,status'])
            ->find((int) ($arguments['distribution_id'] ?? 0));

        if (! $d) {
            throw new InvalidArgumentException('Distribution not found.');
        }

        return [
            'distribution' => [
                'id' => $d->id,
                'invoice' => $d->invoice,
                'status' => $d->status,
                'tier' => $d->tier,
                'from_user' => $d->fromUser,
                'to_user' => $d->toUser,
                'confirmed_at' => optional($d->confirmed_at)?->toIso8601String(),
                'points_awarded_at' => optional($d->points_awarded_at)?->toIso8601String(),
                'items' => $d->relationLoaded('items')
                    ? $d->items->map(fn ($item) => $item->toArray())->all()
                    : [],
            ],
        ];
    }
}

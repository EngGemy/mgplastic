<?php

namespace App\Ai\Tools;

use App\Models\WithdrawalRequest;

class ListWithdrawalRequestsTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_withdrawal_requests';
    }

    public function description(): string
    {
        return 'List plumber withdrawal requests filtered by status.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'status' => [
                'type' => 'string',
                'description' => 'pending, approved, paid, rejected — default pending',
            ],
            'plumber_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 30)));
        $status = $arguments['status'] ?? 'pending';

        $query = WithdrawalRequest::query()
            ->with(['plumber:id,name,phone'])
            ->latest('id');

        if ($status !== 'all' && $status !== null && $status !== '') {
            $query->where('status', $status);
        }
        if (! empty($arguments['plumber_id'])) {
            $query->where('plumber_id', (int) $arguments['plumber_id']);
        }

        $items = $query->limit($limit)->get();

        return [
            'count' => $items->count(),
            'items' => $items->map(fn (WithdrawalRequest $w) => [
                'id' => $w->id,
                'status' => $w->status,
                'amount_cents' => (int) $w->amount_cents,
                'formatted_amount' => $w->formattedAmount(),
                'method' => $w->method,
                'plumber' => $w->plumber ? [
                    'id' => $w->plumber->id,
                    'name' => $w->plumber->name,
                    'phone' => $w->plumber->phone,
                ] : null,
                'created_at' => optional($w->created_at)?->toIso8601String(),
                'approved_at' => optional($w->approved_at)?->toIso8601String(),
                'paid_at' => optional($w->paid_at)?->toIso8601String(),
            ])->all(),
        ];
    }
}

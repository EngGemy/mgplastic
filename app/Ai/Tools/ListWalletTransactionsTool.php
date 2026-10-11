<?php

namespace App\Ai\Tools;

use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Schema;

class ListWalletTransactionsTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_wallet_transactions';
    }

    public function description(): string
    {
        return 'List recent wallet/points transactions (credits/debits). Use for “آخر حركة نقاط”.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'owner_id' => ['type' => 'integer', 'description' => 'Filter by user id'],
            'type' => ['type' => 'string', 'enum' => ['credit', 'debit']],
            'days' => ['type' => 'integer', 'description' => 'Window in days (default 30)'],
            'limit' => ['type' => 'integer', 'description' => 'Default 40, max 100'],
        ]);
    }

    public function handle(array $arguments): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return ['available' => false, 'transactions' => []];
        }

        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 40)));
        $days = max(1, min(365, (int) ($arguments['days'] ?? 30)));

        $query = WalletTransaction::query()
            ->with([
                'wallet.owner:id,name,brand_name,phone,role,network_code',
                'creator:id,name',
            ])
            ->where('created_at', '>=', now()->subDays($days))
            ->latest('id');

        if (! empty($arguments['owner_id'])) {
            $query->whereHas('wallet', fn ($q) => $q->where('owner_id', (int) $arguments['owner_id']));
        }
        if (! empty($arguments['type'])) {
            $query->where('type', $arguments['type']);
        }

        $items = $query->limit($limit)->get();

        return [
            'available' => true,
            'window_days' => $days,
            'returned' => $items->count(),
            'transactions' => $items->map(function (WalletTransaction $tx) {
                $owner = $tx->wallet?->owner;

                return [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'points_delta' => (int) $tx->points_delta,
                    'amount_cents' => (int) $tx->amount_cents,
                    'description' => $tx->description,
                    'meta' => $tx->meta,
                    'owner' => $owner ? [
                        'id' => $owner->id,
                        'name' => $owner->brand_name ?: $owner->name,
                        'role' => $owner->role,
                        'phone' => $owner->phone,
                        'network_code' => $owner->network_code,
                    ] : null,
                    'created_by' => $tx->creator?->name,
                    'created_at' => optional($tx->created_at)?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }
}

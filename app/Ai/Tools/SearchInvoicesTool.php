<?php

namespace App\Ai\Tools;

use App\Models\Invoice;

class SearchInvoicesTool extends AbstractTool
{
    public function name(): string
    {
        return 'search_invoices';
    }

    public function description(): string
    {
        return 'Search invoices by number/serial, status, type, or related user ids.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'q' => ['type' => 'string', 'description' => 'Invoice number or serial'],
            'status' => ['type' => 'string'],
            'invoice_type' => ['type' => 'string'],
            'plumber_id' => ['type' => 'integer'],
            'wholesale_distributor_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $q = trim((string) ($arguments['q'] ?? ''));

        $query = Invoice::query()->latest('id');

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('number', 'like', "%{$q}%")
                    ->orWhere('serial_number', 'like', "%{$q}%");
            });
        }
        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }
        if (! empty($arguments['invoice_type'])) {
            $query->where('invoice_type', $arguments['invoice_type']);
        }
        if (! empty($arguments['plumber_id'])) {
            $query->where('plumber_id', (int) $arguments['plumber_id']);
        }
        if (! empty($arguments['wholesale_distributor_id'])) {
            $query->where('wholesale_distributor_id', (int) $arguments['wholesale_distributor_id']);
        }

        $items = $query->limit($limit)->get([
            'id', 'number', 'serial_number', 'status', 'invoice_type', 'invoice_flow',
            'plumber_id', 'wholesale_distributor_id', 'counterparty_user_id',
            'total_cents', 'currency', 'points_awarded', 'created_at', 'approved_at',
        ]);

        return [
            'count' => $items->count(),
            'items' => $items->map(fn (Invoice $inv) => [
                'id' => $inv->id,
                'number' => $inv->number,
                'serial_number' => $inv->serial_number,
                'status' => $inv->status,
                'invoice_type' => $inv->invoice_type,
                'invoice_flow' => $inv->invoice_flow,
                'plumber_id' => $inv->plumber_id,
                'wholesale_distributor_id' => $inv->wholesale_distributor_id,
                'counterparty_user_id' => $inv->counterparty_user_id,
                'total_cents' => (int) $inv->total_cents,
                'currency' => $inv->currency,
                'points_awarded' => (bool) $inv->points_awarded,
                'created_at' => optional($inv->created_at)?->toIso8601String(),
                'approved_at' => optional($inv->approved_at)?->toIso8601String(),
            ])->all(),
        ];
    }
}

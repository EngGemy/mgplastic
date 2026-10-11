<?php

namespace App\Ai\Tools;

use App\Models\Invoice;
use InvalidArgumentException;

class GetInvoiceTool extends AbstractTool
{
    public function name(): string
    {
        return 'get_invoice';
    }

    public function description(): string
    {
        return 'Get a single invoice with items and related users.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'invoice_id' => ['type' => 'integer'],
            'number' => ['type' => 'string'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $query = Invoice::query()->with([
            'items',
            'plumber:id,name,phone,role',
            'wholesaleDistributor:id,name,phone,role,network_code',
            'counterparty:id,name,phone,role',
        ]);

        if (! empty($arguments['invoice_id'])) {
            $query->where('id', (int) $arguments['invoice_id']);
        } elseif (! empty($arguments['number'])) {
            $query->where(function ($builder) use ($arguments) {
                $builder->where('number', $arguments['number'])
                    ->orWhere('serial_number', $arguments['number']);
            });
        } else {
            throw new InvalidArgumentException('Provide invoice_id or number.');
        }

        /** @var Invoice|null $invoice */
        $invoice = $query->first();
        if (! $invoice) {
            throw new InvalidArgumentException('Invoice not found.');
        }

        return [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'serial_number' => $invoice->serial_number,
                'status' => $invoice->status,
                'invoice_type' => $invoice->invoice_type,
                'invoice_flow' => $invoice->invoice_flow,
                'subtotal_cents' => (int) $invoice->subtotal_cents,
                'tax_cents' => (int) $invoice->tax_cents,
                'total_cents' => (int) $invoice->total_cents,
                'currency' => $invoice->currency,
                'points_awarded' => (bool) $invoice->points_awarded,
                'profit_percent' => $invoice->profit_percent,
                'rejection_reason' => $invoice->rejection_reason,
                'created_at' => optional($invoice->created_at)?->toIso8601String(),
                'approved_at' => optional($invoice->approved_at)?->toIso8601String(),
                'plumber' => $invoice->plumber,
                'wholesale_distributor' => $invoice->wholesaleDistributor,
                'counterparty' => $invoice->counterparty,
                'items' => $invoice->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id ?? null,
                    'name' => $item->name ?? $item->product_name ?? null,
                    'qty' => $item->qty ?? $item->quantity ?? null,
                    'unit_price_cents' => $item->unit_price_cents ?? null,
                    'line_total_cents' => $item->line_total_cents ?? $item->total_cents ?? null,
                    'points' => $item->points ?? $item->points_value ?? null,
                ])->all(),
            ],
        ];
    }
}

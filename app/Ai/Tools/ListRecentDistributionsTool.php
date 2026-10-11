<?php

namespace App\Ai\Tools;

use App\Models\InvoiceDistribution;

class ListRecentDistributionsTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_recent_distributions';
    }

    public function description(): string
    {
        return 'List newest invoice distributions with from/to users, status, tier, and product line items (names + qty). Answers “آخر توزيع / المنتجات الموزعة”.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'status' => ['type' => 'string', 'description' => 'draft, confirmed, points_awarded, …'],
            'tier' => ['type' => 'integer'],
            'from_user_id' => ['type' => 'integer'],
            'to_user_id' => ['type' => 'integer'],
            'days' => ['type' => 'integer', 'description' => 'Created within N days (default 60)'],
            'limit' => ['type' => 'integer', 'description' => 'Default 20, max 50'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $days = max(1, min(365, (int) ($arguments['days'] ?? 60)));

        $query = InvoiceDistribution::query()
            ->with([
                'fromUser:id,name,brand_name,role,network_code',
                'toUser:id,name,brand_name,role,network_code',
                'invoice:id,number,serial,status,invoice_type',
                'items.invoiceItem.product.translations',
            ])
            ->where('created_at', '>=', now()->subDays($days))
            ->latest('id');

        foreach (['status', 'tier', 'from_user_id', 'to_user_id'] as $field) {
            if (isset($arguments[$field]) && $arguments[$field] !== '' && $arguments[$field] !== null) {
                $query->where($field, $arguments[$field]);
            }
        }

        $items = $query->limit($limit)->get();

        return [
            'window_days' => $days,
            'returned' => $items->count(),
            'latest_distribution' => $items->first() ? $this->mapDistribution($items->first()) : null,
            'distributions' => $items->map(fn (InvoiceDistribution $d) => $this->mapDistribution($d))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function mapDistribution(InvoiceDistribution $d): array
    {
        $lines = $d->items->map(function ($item) {
            $product = $item->invoiceItem?->product;
            $name = null;
            if ($product) {
                $name = optional($product->translate('ar'))->name
                    ?? optional($product->translate('en'))->name
                    ?? ('Product #'.$product->id);
            }

            return [
                'distribution_item_id' => $item->id,
                'product_id' => $product?->id,
                'product_name' => $name,
                'quantity' => (int) ($item->quantity ?? 0),
                'points_value' => (int) ($item->points_value ?? 0),
            ];
        })->values()->all();

        return [
            'id' => $d->id,
            'invoice_id' => $d->invoice_id,
            'invoice_number' => $d->invoice?->number ?? $d->invoice?->serial,
            'status' => $d->status,
            'tier' => $d->tier,
            'from_user' => $d->fromUser ? [
                'id' => $d->fromUser->id,
                'name' => $d->fromUser->brand_name ?: $d->fromUser->name,
                'role' => $d->fromUser->role,
                'network_code' => $d->fromUser->network_code,
            ] : null,
            'to_user' => $d->toUser ? [
                'id' => $d->toUser->id,
                'name' => $d->toUser->brand_name ?: $d->toUser->name,
                'role' => $d->toUser->role,
                'network_code' => $d->toUser->network_code,
            ] : null,
            'items' => $lines,
            'items_count' => count($lines),
            'confirmed_at' => optional($d->confirmed_at)?->toIso8601String(),
            'points_awarded_at' => optional($d->points_awarded_at)?->toIso8601String(),
            'created_at' => optional($d->created_at)?->toIso8601String(),
        ];
    }
}

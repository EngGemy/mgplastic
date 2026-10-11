<?php

namespace App\Ai\Tools;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\Schema;

class ListRecentActivityTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_recent_activity';
    }

    public function description(): string
    {
        return 'Unified recent activity feed across stores, plumbers, products, invoices, distributions, orders, point transactions, and withdrawals. Answers “آخر التحديثات / آخر النشاط”.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'types' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => [
                        'stores', 'plumbers', 'products', 'invoices',
                        'distributions', 'orders', 'points', 'withdrawals',
                    ],
                ],
                'description' => 'Omit for all types',
            ],
            'per_type' => ['type' => 'integer', 'description' => 'Items per type (default 8, max 20)'],
            'days' => ['type' => 'integer', 'description' => 'Window days (default 30)'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $perType = max(1, min(20, (int) ($arguments['per_type'] ?? 8)));
        $days = max(1, min(365, (int) ($arguments['days'] ?? 30)));
        $since = now()->subDays($days);
        $wanted = collect($arguments['types'] ?? [])
            ->filter(fn ($t) => is_string($t))
            ->values();
        $all = $wanted->isEmpty();

        $events = collect();

        if ($all || $wanted->contains('stores')) {
            $events = $events->merge(
                User::query()
                    ->whereIn('role', ['wholesale_distributor', 'retail_trader'])
                    ->where('created_at', '>=', $since)
                    ->latest('id')
                    ->limit($perType)
                    ->get(['id', 'name', 'brand_name', 'role', 'phone', 'network_code', 'created_at'])
                    ->map(fn (User $u) => [
                        'type' => 'store_created',
                        'at' => optional($u->created_at)?->toIso8601String(),
                        'summary' => 'متجر جديد: '.($u->brand_name ?: $u->name).' ('.$u->role.')',
                        'ref' => ['user_id' => $u->id, 'role' => $u->role, 'network_code' => $u->network_code],
                    ])
            );
        }

        if ($all || $wanted->contains('plumbers')) {
            $events = $events->merge(
                User::query()
                    ->where('role', User::ROLE_PLUMBER)
                    ->where('created_at', '>=', $since)
                    ->latest('id')
                    ->limit($perType)
                    ->get(['id', 'name', 'phone', 'network_code', 'created_at'])
                    ->map(fn (User $u) => [
                        'type' => 'plumber_created',
                        'at' => optional($u->created_at)?->toIso8601String(),
                        'summary' => 'سبّاك جديد: '.$u->name,
                        'ref' => ['user_id' => $u->id, 'phone' => $u->phone, 'network_code' => $u->network_code],
                    ])
            );
        }

        if ($all || $wanted->contains('products')) {
            $events = $events->merge(
                Product::query()
                    ->with('translations')
                    ->where('created_at', '>=', $since)
                    ->latest('id')
                    ->limit($perType)
                    ->get()
                    ->map(function (Product $p) {
                        $name = optional($p->translate('ar'))->name
                            ?? optional($p->translate('en'))->name
                            ?? ('Product #'.$p->id);

                        return [
                            'type' => 'product_created',
                            'at' => optional($p->created_at)?->toIso8601String(),
                            'summary' => 'منتج جديد: '.$name,
                            'ref' => ['product_id' => $p->id, 'name' => $name],
                        ];
                    })
            );
        }

        if ($all || $wanted->contains('invoices')) {
            if (Schema::hasTable('invoices')) {
                $events = $events->merge(
                    Invoice::query()
                        ->where('created_at', '>=', $since)
                        ->latest('id')
                        ->limit($perType)
                        ->get(['id', 'number', 'serial', 'status', 'invoice_type', 'created_at'])
                        ->map(fn (Invoice $i) => [
                            'type' => 'invoice_created',
                            'at' => optional($i->created_at)?->toIso8601String(),
                            'summary' => 'فاتورة: '.($i->number ?: $i->serial ?: '#'.$i->id).' ('.$i->status.')',
                            'ref' => [
                                'invoice_id' => $i->id,
                                'status' => $i->status,
                                'invoice_type' => $i->invoice_type,
                            ],
                        ])
                );
            }
        }

        if ($all || $wanted->contains('distributions')) {
            $events = $events->merge(
                InvoiceDistribution::query()
                    ->with(['fromUser:id,name', 'toUser:id,name'])
                    ->where('created_at', '>=', $since)
                    ->latest('id')
                    ->limit($perType)
                    ->get()
                    ->map(fn (InvoiceDistribution $d) => [
                        'type' => 'distribution_created',
                        'at' => optional($d->created_at)?->toIso8601String(),
                        'summary' => 'توزيع #'.$d->id.' من '.($d->fromUser?->name ?? '?').' إلى '.($d->toUser?->name ?? '?').' ('.$d->status.')',
                        'ref' => [
                            'distribution_id' => $d->id,
                            'status' => $d->status,
                            'tier' => $d->tier,
                        ],
                    ])
            );
        }

        if ($all || $wanted->contains('orders')) {
            if (Schema::hasTable('orders')) {
                $events = $events->merge(
                    Order::query()
                        ->where('created_at', '>=', $since)
                        ->latest('id')
                        ->limit($perType)
                        ->get(['id', 'status', 'channel', 'created_at'])
                        ->map(fn (Order $o) => [
                            'type' => 'order_created',
                            'at' => optional($o->created_at)?->toIso8601String(),
                            'summary' => 'طلبية #'.$o->id.' ('.$o->status.'/'.$o->channel.')',
                            'ref' => ['order_id' => $o->id, 'status' => $o->status, 'channel' => $o->channel],
                        ])
                );
            }
        }

        if ($all || $wanted->contains('points')) {
            if (Schema::hasTable('wallet_transactions')) {
                $events = $events->merge(
                    WalletTransaction::query()
                        ->with('wallet.owner:id,name,role')
                        ->where('created_at', '>=', $since)
                        ->latest('id')
                        ->limit($perType)
                        ->get()
                        ->map(function (WalletTransaction $tx) {
                            $owner = $tx->wallet?->owner;
                            $delta = (int) $tx->points_delta;

                            return [
                                'type' => 'points_transaction',
                                'at' => optional($tx->created_at)?->toIso8601String(),
                                'summary' => 'نقاط '.($delta >= 0 ? '+'.$delta : (string) $delta).' لـ '.($owner?->name ?? 'مستخدم'),
                                'ref' => [
                                    'transaction_id' => $tx->id,
                                    'owner_id' => $owner?->id,
                                    'points_delta' => $delta,
                                    'tx_type' => $tx->type,
                                ],
                            ];
                        })
                );
            }
        }

        if ($all || $wanted->contains('withdrawals')) {
            if (Schema::hasTable('withdrawal_requests')) {
                $events = $events->merge(
                    WithdrawalRequest::query()
                        ->with('plumber:id,name,phone')
                        ->where('created_at', '>=', $since)
                        ->latest('id')
                        ->limit($perType)
                        ->get()
                        ->map(fn (WithdrawalRequest $w) => [
                            'type' => 'withdrawal_created',
                            'at' => optional($w->created_at)?->toIso8601String(),
                            'summary' => 'طلب سحب #'.$w->id.' — '.($w->plumber?->name ?? '').' ('.$w->status.')',
                            'ref' => [
                                'withdrawal_id' => $w->id,
                                'status' => $w->status,
                                'amount_cents' => $w->amount_cents ?? null,
                            ],
                        ])
                );
            }
        }

        $sorted = $events
            ->filter(fn ($e) => filled($e['at'] ?? null))
            ->sortByDesc('at')
            ->values();

        return [
            'window_days' => $days,
            'returned' => $sorted->count(),
            'latest' => $sorted->first(),
            'activity' => $sorted->all(),
        ];
    }
}

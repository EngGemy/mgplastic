<?php

namespace App\Ai\Tools;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class ReportInvoicesTool extends AbstractTool
{
    public function name(): string
    {
        return 'report_invoices';
    }

    public function description(): string
    {
        return 'Invoices report by status/type for a date window.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'days' => ['type' => 'integer', 'description' => 'Window in days (default 30)'],
        ]);
    }

    public function handle(array $arguments): array
    {
        $days = max(1, min(365, (int) ($arguments['days'] ?? 30)));
        $since = now()->subDays($days);

        $base = Invoice::query()->where('created_at', '>=', $since);

        return [
            'window_days' => $days,
            'total' => (clone $base)->count(),
            'total_cents' => (int) (clone $base)->sum('total_cents'),
            'by_status' => (clone $base)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'by_type' => (clone $base)
                ->select('invoice_type', DB::raw('count(*) as total'))
                ->groupBy('invoice_type')
                ->pluck('total', 'invoice_type')
                ->all(),
            'pending_review' => Invoice::query()->where('status', 'pending_review')->count(),
        ];
    }
}

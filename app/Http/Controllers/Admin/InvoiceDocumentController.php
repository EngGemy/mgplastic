<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceDocumentController extends Controller
{
    public function print(Invoice $invoice, Request $request): Response
    {
        $this->authorizeInvoice($invoice);

        $invoice->load([
            'items.product.translations',
            'wholesaleDistributor',
            'plumber',
            'issuer',
            'reviewer',
        ]);

        return response()->view('invoices.document', [
            'invoice' => $invoice,
            'autoPrint' => $request->boolean('auto'),
            'mode' => 'print',
        ]);
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        $this->authorizeInvoice($invoice);

        $invoice->load([
            'items.product.translations',
            'wholesaleDistributor',
            'plumber',
            'issuer',
            'reviewer',
        ]);

        $html = view('invoices.document', [
            'invoice' => $invoice,
            'autoPrint' => false,
            'mode' => 'download',
        ])->render();

        $filename = ($invoice->number ?? 'invoice-'.$invoice->id).'.html';

        return response()->streamDownload(
            fn () => print($html),
            $filename,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    public function exportJson(Invoice $invoice): Response
    {
        $this->authorizeInvoice($invoice);

        $invoice->load(['items.product.translations', 'wholesaleDistributor', 'plumber', 'issuer']);

        $payload = [
            'serial_number' => $invoice->serial_number,
            'number' => $invoice->number,
            'invoice_type' => $invoice->invoice_type,
            'status' => $invoice->status,
            'currency' => 'LYD',
            'issued_at' => $invoice->created_at?->toIso8601String(),
            'approved_at' => $invoice->approved_at?->toIso8601String(),
            'wholesale_distributor' => $invoice->wholesaleDistributor?->only(['id', 'name', 'phone']),
            'plumber' => $invoice->plumber?->only(['id', 'name', 'phone']),
            'issuer' => $invoice->issuer?->only(['id', 'name']),
            'subtotal_dinars' => round($invoice->subtotal_cents / 100, 2),
            'total_dinars' => round($invoice->total_cents / 100, 2),
            'total_quantity' => (int) $invoice->items->sum('quantity'),
            'total_points' => (int) $invoice->items->sum('total_points'),
            'items' => $invoice->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => localized_name($item->product, 'name'),
                'quantity' => (int) $item->quantity,
                'unit_price_dinars' => round($item->unit_price_cents / 100, 2),
                'line_total_dinars' => round($item->quantity * $item->unit_price_cents / 100, 2),
                'points_per_unit' => (float) $item->points_per_unit,
                'total_points' => (int) $item->total_points,
            ])->values(),
        ];

        $filename = ($invoice->number ?? 'invoice-'.$invoice->id).'.json';

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function authorizeInvoice(Invoice $invoice): void
    {
        $user = auth()->user();

        abort_unless($user, 403);

        if (in_array($user->role, ['super_admin', 'admin'])) {
            return;
        }

        if ($user->role === 'wholesale_distributor' && $invoice->wholesale_distributor_id === $user->id) {
            return;
        }

        if ($user->role === 'plumber' && $invoice->plumber_id === $user->id) {
            return;
        }

        abort(403);
    }
}

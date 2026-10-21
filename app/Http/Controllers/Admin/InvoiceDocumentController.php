<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceDocumentController extends Controller
{
    public function print(Invoice $invoice, Request $request): Response
    {
        $this->authorizeInvoice($invoice);

        $invoice->load($this->documentRelations());

        return response()->view('invoices.document', [
            'invoice' => $invoice,
            'autoPrint' => $request->boolean('auto'),
            'mode' => 'print',
            'returnSummary' => $invoice->isOutgoing() ? $invoice->returnSummary() : null,
        ]);
    }

    /** Authenticated panel download (admin / warehouse / distributor / trader). */
    public function pdf(Invoice $invoice)
    {
        $this->authorizeInvoice($invoice);

        return $this->streamPdf($invoice);
    }

    /** Public signed link for WhatsApp sharing (no login). */
    public function pdfPublic(Invoice $invoice)
    {
        return $this->streamPdf($invoice);
    }

    protected function streamPdf(Invoice $invoice)
    {
        $invoice->load($this->documentRelations());

        $filename = ($invoice->number ?? 'invoice-'.$invoice->id).'.pdf';

        return Pdf::loadView('invoices.pdf', ['invoice' => $invoice])
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }

    /** @return list<string> */
    protected function documentRelations(): array
    {
        return [
            'items.product.translations',
            'wholesaleDistributor',
            'plumber',
            'issuer',
            'reviewer',
            'counterparty',
            'sourceDistribution.items.invoiceItem.product.translations',
            'returns.items.product.translations',
            'returns.fromUser',
            'returns.toUser',
        ];
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        $this->authorizeInvoice($invoice);

        $invoice->load($this->documentRelations());

        $html = view('invoices.document', [
            'invoice' => $invoice,
            'autoPrint' => false,
            'mode' => 'download',
            'returnSummary' => $invoice->isOutgoing() ? $invoice->returnSummary() : null,
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

        $invoice->load([
            'items.product.translations',
            'wholesaleDistributor',
            'plumber',
            'issuer',
            'returns.items.product.translations',
            'sourceDistribution.items',
        ]);

        $summary = $invoice->isOutgoing() ? $invoice->returnSummary() : null;

        $payload = [
            'serial_number' => $invoice->serial_number,
            'number' => $invoice->number,
            'invoice_type' => $invoice->invoice_type,
            'invoice_flow' => $invoice->invoice_flow,
            'status' => $invoice->status,
            'issued_at' => $invoice->created_at?->toIso8601String(),
            'approved_at' => $invoice->approved_at?->toIso8601String(),
            'wholesale_distributor' => $invoice->wholesaleDistributor?->only(['id', 'name', 'phone']),
            'plumber' => $invoice->plumber?->only(['id', 'name', 'phone']),
            'issuer' => $invoice->issuer?->only(['id', 'name']),
            'total_quantity' => $summary['sold_qty'] ?? (int) $invoice->items->sum('quantity'),
            'total_points' => $summary['sold_points'] ?? (int) $invoice->items->sum('total_points'),
            'points_awarded' => (int) ($invoice->points_awarded ?? 0),
            'returns' => $summary ? [
                'returned_quantity' => $summary['returned_qty'],
                'returned_points' => $summary['returned_points'],
                'net_quantity' => $summary['net_qty'],
                'net_points' => $summary['net_points'],
                'count' => $summary['returns_count'],
                'entries' => $invoice->returns->where('status', 'confirmed')->values()->map(fn ($ret) => [
                    'return_number' => $ret->return_number,
                    'total_quantity' => (int) $ret->total_quantity,
                    'total_points' => (int) $ret->total_points,
                    'confirmed_at' => $ret->confirmed_at?->toIso8601String(),
                    'items' => $ret->items->map(fn ($ri) => [
                        'product_name' => localized_name($ri->product, 'name'),
                        'quantity' => (int) $ri->quantity,
                        'points_value' => (int) $ri->points_value,
                    ])->values(),
                ]),
            ] : null,
            'items' => $invoice->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => localized_name($item->product, 'name'),
                'quantity' => (int) $item->quantity,
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

        if ($user->role === 'retail_trader' && (int) $invoice->counterparty_user_id === (int) $user->id) {
            return;
        }

        if ($user->role === 'plumber' && $invoice->plumber_id === $user->id) {
            return;
        }

        abort(403);
    }
}

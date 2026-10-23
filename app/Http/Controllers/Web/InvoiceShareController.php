<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Response;

class InvoiceShareController extends Controller
{
    public function show(Invoice $invoice): Response
    {
        $invoice->load([
            'items.product.translations',
            'items.product.latestPointsRateChange',
            'wholesaleDistributor',
            'plumber',
            'issuer',
            'reviewer',
            'counterparty',
            'sourceDistribution.items.invoiceItem.product.translations',
            'sourceDistribution.items.invoiceItem.product.latestPointsRateChange',
            'returns.items.product.translations',
            'returns.fromUser',
            'returns.toUser',
        ]);

        return response()->view('invoices.document', [
            'invoice' => $invoice,
            'autoPrint' => false,
            'mode' => 'share',
            'returnSummary' => $invoice->isOutgoing() ? $invoice->returnSummary() : null,
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WholesaleInvoiceService
{
    public function __construct(
        protected DistributionService $distributions,
    ) {}
    /**
     * @param  array<int, array{product_id:int, quantity:int, unit_price_dinars?:float, unit_price_cents?:float|int, points_per_unit:float|int}>  $lines
     *
     * @throws \DomainException
     */
    public function issueFromCart(User $wholesaler, array $lines, User $issuedBy): Invoice
    {
        if ($wholesaler->role !== 'wholesale_distributor') {
            throw new \DomainException('يجب اختيار موزع جملة');
        }

        if (empty($lines)) {
            throw new \DomainException('أضف منتجاً واحداً على الأقل');
        }

        return DB::transaction(function () use ($wholesaler, $lines, $issuedBy) {
            $subtotalCents = 0;
            $preparedLines = [];

            foreach ($lines as $line) {
                $product = Product::with('translations')->findOrFail($line['product_id']);
                $qty = max(1, (int) $line['quantity']);

                $unitPriceCents = isset($line['unit_price_dinars'])
                    ? (int) round(((float) $line['unit_price_dinars']) * 100)
                    : (int) round((float) ($line['unit_price_cents'] ?? 0));

                $pointsPerUnit = (float) ($line['points_per_unit'] ?? $product->points_per_unit ?? 0);
                $linePoints = (int) floor($qty * $pointsPerUnit);

                $subtotalCents += $unitPriceCents * $qty;

                $preparedLines[] = [
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price_cents' => $unitPriceCents,
                    'points_per_unit' => $pointsPerUnit,
                    'total_points' => $linePoints,
                ];
            }

            [$serial, $number] = app(InvoiceNumberService::class)->reserveNext('wholesale_pos');

            $invoice = Invoice::create([
                'serial_number' => $serial,
                'invoice_type' => 'wholesale_pos',
                'invoice_flow' => 'incoming',
                'wholesale_distributor_id' => $wholesaler->id,
                'plumber_id' => null,
                'vendor_store_id' => null,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => 0,
                'total_cents' => $subtotalCents,
                'currency' => 'LYD',
                'number' => $number,
                'attachment_path' => null,
                'status' => 'approved',
                'approved_at' => now(),
                'reviewed_by' => $issuedBy->id,
                'issued_by' => $issuedBy->id,
                'points_awarded' => 0,
                'profit_percent' => null,
            ]);

            foreach ($preparedLines as $row) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    ...$row,
                ]);
            }

            $invoice->load('items');

            $this->ensureTierOneReceipt($invoice, $wholesaler, $issuedBy);

            return $invoice->load(['items.product.translations', 'wholesaleDistributor']);
        });
    }

    protected function ensureTierOneReceipt(Invoice $invoice, User $wholesaler, User $issuedBy): void
    {
        $exists = $invoice->distributions()
            ->where('tier', 1)
            ->where('to_user_id', $wholesaler->id)
            ->exists();

        if ($exists) {
            return;
        }

        $fromUser = User::where('role', 'super_admin')->first() ?? $issuedBy;

        $items = $invoice->items->map(fn (InvoiceItem $item) => [
            'invoice_item_id' => $item->id,
            'quantity' => $item->quantity,
        ])->all();

        $distribution = $this->distributions->createDistribution(
            invoice: $invoice,
            fromUser: $fromUser,
            toUser: $wholesaler,
            tier: 1,
            items: $items,
            skipCallerCheck: true,
        );

        $this->distributions->confirmDistribution($distribution);
    }
}

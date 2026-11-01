<?php

namespace App\Services\Ocr;

class InvoiceOcrValidator
{
    /**
     * Annotate draft lines/header with needs_review flags. Never fails hard.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function annotate(array $draft): array
    {
        $qtyMin = (float) config('services.ocr.qty_min', 0.001);
        $qtyMax = (float) config('services.ocr.qty_max', 100000);
        $tol = (float) config('services.ocr.money_tolerance', 0.05);

        $lines = [];
        $sumLineTotals = 0.0;
        $hasLineTotals = false;

        foreach ((array) ($draft['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $flags = [];
            $qty = isset($line['qty']) ? (float) $line['qty'] : null;
            $unit = isset($line['unit_price']) && $line['unit_price'] !== null ? (float) $line['unit_price'] : null;
            $total = isset($line['line_total']) && $line['line_total'] !== null ? (float) $line['line_total'] : null;

            if ($qty === null || $qty < $qtyMin || $qty > $qtyMax) {
                $flags[] = 'qty';
            }

            if ($qty !== null && $unit !== null && $total !== null) {
                $expected = round($qty * $unit, 3);
                if (abs($expected - $total) > max($tol, $tol * max(abs($total), 1))) {
                    $flags[] = 'line_math';
                }
            }

            if ($total !== null) {
                $sumLineTotals += $total;
                $hasLineTotals = true;
            }

            $confidence = max(0, min(1, (float) ($line['confidence'] ?? 0.5)));
            if ($confidence < 0.6) {
                $flags[] = 'low_confidence';
            }

            $line['needs_review'] = array_values(array_unique($flags));
            $lines[] = $line;
        }

        $headerFlags = [];
        $subtotal = isset($draft['subtotal']) && $draft['subtotal'] !== null ? (float) $draft['subtotal'] : null;
        if ($hasLineTotals && $subtotal !== null) {
            if (abs($sumLineTotals - $subtotal) > max($tol, $tol * max(abs($subtotal), 1))) {
                $headerFlags[] = 'subtotal_mismatch';
            }
        }

        $draft['lines'] = $lines;
        $draft['needs_review'] = $headerFlags;

        return $draft;
    }
}

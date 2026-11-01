<?php

namespace App\Services;

use App\Models\Invoice;
use App\Services\Ai\GeminiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvoiceOcrService
{
    public function __construct(
        protected GeminiClient $gemini,
    ) {}

    public function process(Invoice $invoice): Invoice
    {
        $invoice->forceFill([
            'ocr_status' => 'processing',
            'ocr_error' => null,
        ])->save();

        if (! filled($invoice->attachment_path)) {
            return $this->fail($invoice, 'لا توجد صورة مرفقة للفاتورة');
        }

        $absolute = Storage::disk('public')->path($invoice->attachment_path);
        $mime = $this->guessMime($invoice->attachment_path);

        $result = $this->gemini->extractInvoiceFromImage($absolute, $mime);

        Log::info('[OCR] invoice extract finished', [
            'invoice_id' => $invoice->id,
            'ok' => $result['ok'],
            'elapsed_ms' => $result['elapsed_ms'] ?? null,
            'model' => $result['model'] ?? null,
        ]);

        if (! ($result['ok'] ?? false) || ! is_array($result['draft'] ?? null)) {
            return $this->fail($invoice, (string) ($result['error'] ?? 'تعذّرت قراءة الفاتورة'));
        }

        $invoice->forceFill([
            'ocr_status' => 'ready',
            'ocr_draft' => $result['draft'],
            'ocr_error' => null,
        ])->save();

        return $invoice->fresh();
    }

    /**
     * Persist admin-edited draft without confirming onto invoice money fields.
     *
     * @param  array<string, mixed>  $draft
     */
    public function saveDraft(Invoice $invoice, array $draft): Invoice
    {
        $normalized = $this->normalizeDraft($draft);

        $invoice->forceFill([
            'ocr_draft' => $normalized,
            'ocr_status' => 'ready',
            'ocr_error' => null,
        ])->save();

        return $invoice->fresh();
    }

    /**
     * Apply reviewed draft to invoice header fields. Does not create product line items
     * (product_id is required on invoice_items). Lines stay in ocr_draft.
     *
     * @param  array<string, mixed>|null  $draft
     */
    public function confirm(Invoice $invoice, ?array $draft = null): Invoice
    {
        $source = $draft ?? (array) ($invoice->ocr_draft ?? []);
        if ($source === []) {
            throw new \InvalidArgumentException('لا توجد مسودة OCR للتأكيد');
        }

        $normalized = $this->normalizeDraft($source);

        $invoice->forceFill([
            'ocr_draft' => $normalized,
            'ocr_status' => 'ready',
            'ocr_error' => null,
            'ocr_confirmed_at' => now(),
            'number' => $normalized['number'] ?: $invoice->number,
            'currency' => $normalized['currency'] ?: ($invoice->currency ?: 'LYD'),
            'subtotal_cents' => $this->toCents($normalized['subtotal']),
            'tax_cents' => $this->toCents($normalized['tax']),
            'total_cents' => $this->toCents($normalized['total']),
        ])->save();

        return $invoice->fresh();
    }

    protected function fail(Invoice $invoice, string $message): Invoice
    {
        $invoice->forceFill([
            'ocr_status' => 'failed',
            'ocr_error' => mb_substr($message, 0, 500),
        ])->save();

        return $invoice->fresh();
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function normalizeDraft(array $draft): array
    {
        $lines = [];
        foreach ((array) ($draft['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $raw = trim((string) ($line['raw_text'] ?? $line['name'] ?? ''));
            $name = trim((string) ($line['name'] ?? $raw));
            if ($raw === '' && $name === '') {
                continue;
            }
            $lines[] = [
                'raw_text' => $raw !== '' ? $raw : $name,
                'name' => $name !== '' ? $name : $raw,
                'qty' => (float) ($line['qty'] ?? 1),
                'unit_price' => isset($line['unit_price']) && $line['unit_price'] !== ''
                    ? (float) $line['unit_price']
                    : null,
                'line_total' => isset($line['line_total']) && $line['line_total'] !== ''
                    ? (float) $line['line_total']
                    : null,
                'confidence' => max(0, min(1, (float) ($line['confidence'] ?? 0.5))),
                'needs_review' => array_values((array) ($line['needs_review'] ?? [])),
                'candidates' => array_values((array) ($line['candidates'] ?? [])),
                'match_decision' => $line['match_decision'] ?? null,
                'matched_product_id' => $line['matched_product_id'] ?? null,
            ];
        }

        $source = (string) ($draft['source_type'] ?? 'unknown');
        if (! in_array($source, ['handwritten', 'thermal', 'printed', 'unknown'], true)) {
            $source = 'unknown';
        }

        return [
            'vendor' => filled($draft['vendor'] ?? null) ? trim((string) $draft['vendor']) : null,
            'number' => filled($draft['number'] ?? null) ? trim((string) $draft['number']) : null,
            'date' => filled($draft['date'] ?? null) ? trim((string) $draft['date']) : null,
            'currency' => filled($draft['currency'] ?? null) ? trim((string) $draft['currency']) : 'LYD',
            'subtotal' => $this->nullableFloat($draft['subtotal'] ?? null),
            'tax' => $this->nullableFloat($draft['tax'] ?? null),
            'total' => $this->nullableFloat($draft['total'] ?? null),
            'lines' => $lines,
            'overall_confidence' => max(0, min(1, (float) ($draft['overall_confidence'] ?? 0.5))),
            'source_type' => $source,
        ];
    }

    protected function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 3);
    }

    protected function toCents(mixed $major): ?int
    {
        if ($major === null || $major === '') {
            return null;
        }

        return (int) round(((float) $major) * 100);
    }

    protected function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}

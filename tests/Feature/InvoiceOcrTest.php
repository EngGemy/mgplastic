<?php

namespace Tests\Feature;

use App\Jobs\ProcessInvoiceOcrJob;
use App\Models\Invoice;
use App\Services\InvoiceOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceOcrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.gemini.key' => 'fake-gemini-key',
            'services.gemini.model' => 'gemini-2.0-flash',
            'services.gemini.ocr_timeout' => 30,
            'services.gemini.daily_limit' => 300,
        ]);
    }

    public function test_ocr_job_stores_ready_draft_from_gemini(): void
    {
        $path = 'invoices/2026/11/01/sample.jpg';
        Storage::disk('public')->put($path, $this->tinyJpeg());

        $invoice = Invoice::factory()->create([
            'attachment_path' => $path,
            'ocr_status' => 'idle',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'vendor' => 'محل النور',
                                'number' => 'A-12',
                                'date' => '2026-11-01',
                                'currency' => 'LYD',
                                'subtotal' => 50,
                                'tax' => 0,
                                'total' => 50,
                                'lines' => [
                                    ['name' => 'ماسورة', 'qty' => 2, 'unit_price' => 25, 'line_total' => 50, 'confidence' => 0.9],
                                ],
                                'overall_confidence' => 0.85,
                                'source_type' => 'thermal',
                            ], JSON_UNESCAPED_UNICODE),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        ProcessInvoiceOcrJob::dispatchSync($invoice);

        $invoice->refresh();
        $this->assertSame('ready', $invoice->ocr_status);
        $this->assertNull($invoice->ocr_error);
        $this->assertSame('محل النور', $invoice->ocr_draft['vendor']);
        $this->assertSame('thermal', $invoice->ocr_draft['source_type']);
        $this->assertCount(1, $invoice->ocr_draft['lines']);
        $this->assertSame('ماسورة', $invoice->ocr_draft['lines'][0]['name']);
    }

    public function test_ocr_job_marks_failed_when_gemini_errors(): void
    {
        $path = 'invoices/2026/11/01/bad.jpg';
        Storage::disk('public')->put($path, $this->tinyJpeg());

        $invoice = Invoice::factory()->create([
            'attachment_path' => $path,
            'ocr_status' => 'idle',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429),
        ]);

        ProcessInvoiceOcrJob::dispatchSync($invoice);

        $invoice->refresh();
        $this->assertSame('failed', $invoice->ocr_status);
        $this->assertNotEmpty($invoice->ocr_error);
    }

    public function test_confirm_writes_header_amounts_from_draft(): void
    {
        $invoice = Invoice::factory()->create([
            'attachment_path' => 'invoices/x.jpg',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'number' => 'OLD',
            'ocr_status' => 'ready',
            'ocr_draft' => [
                'vendor' => 'تاجر',
                'number' => 'INV-9',
                'date' => '2026-11-01',
                'currency' => 'LYD',
                'subtotal' => 12.5,
                'tax' => 0,
                'total' => 12.5,
                'lines' => [
                    ['name' => 'صنف', 'qty' => 1, 'unit_price' => 12.5, 'line_total' => 12.5, 'confidence' => 0.7],
                ],
                'overall_confidence' => 0.7,
                'source_type' => 'printed',
            ],
        ]);

        app(InvoiceOcrService::class)->confirm($invoice);

        $invoice->refresh();
        $this->assertSame('INV-9', $invoice->number);
        $this->assertSame(1250, $invoice->subtotal_cents);
        $this->assertSame(0, $invoice->tax_cents);
        $this->assertSame(1250, $invoice->total_cents);
        $this->assertNotNull($invoice->ocr_confirmed_at);
        $this->assertSame(0, $invoice->items()->count());
    }

    public function test_save_draft_allows_edit_and_add_lines(): void
    {
        $invoice = Invoice::factory()->create([
            'ocr_status' => 'ready',
            'ocr_draft' => [
                'vendor' => 'قديم',
                'number' => null,
                'currency' => 'LYD',
                'subtotal' => 10,
                'tax' => 0,
                'total' => 10,
                'lines' => [
                    ['name' => 'أ', 'qty' => 1, 'unit_price' => 10, 'line_total' => 10, 'confidence' => 0.5],
                ],
                'overall_confidence' => 0.5,
                'source_type' => 'handwritten',
            ],
        ]);

        app(InvoiceOcrService::class)->saveDraft($invoice, [
            'vendor' => 'جديد',
            'number' => 'N-1',
            'currency' => 'LYD',
            'subtotal' => 30,
            'tax' => 0,
            'total' => 30,
            'source_type' => 'handwritten',
            'overall_confidence' => 0.5,
            'lines' => [
                ['name' => 'أ', 'qty' => 1, 'unit_price' => 10, 'line_total' => 10, 'confidence' => 0.5],
                ['name' => 'ب مضاف', 'qty' => 2, 'unit_price' => 10, 'line_total' => 20, 'confidence' => 1],
            ],
        ]);

        $invoice->refresh();
        $this->assertSame('جديد', $invoice->ocr_draft['vendor']);
        $this->assertCount(2, $invoice->ocr_draft['lines']);
        $this->assertSame('ب مضاف', $invoice->ocr_draft['lines'][1]['name']);
    }

    public function test_ocr_service_normalize_and_status_helpers(): void
    {
        $invoice = Invoice::factory()->create([
            'ocr_status' => 'idle',
            'attachment_path' => null,
        ]);

        $failed = app(InvoiceOcrService::class)->process($invoice);
        $this->assertSame('failed', $failed->ocr_status);
        $this->assertStringContainsString('صورة', (string) $failed->ocr_error);
    }

    protected function tinyJpeg(): string
    {
        // Minimal valid JPEG
        return base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAG/AP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bf//Z'
        );
    }
}

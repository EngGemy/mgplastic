<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\CustomerWhatsAppNotifier;
use App\Services\InvoiceWhatsAppShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InvoicePdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_download_invoice_pdf(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'phone' => '0911111111',
            'is_active' => true,
            'is_approved' => true,
        ]);

        $invoice = Invoice::query()->create([
            'number' => 'INV-PDF-1',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'status' => 'approved',
            'wholesale_distributor_id' => $wholesaler->id,
            'issued_by' => $wholesaler->id,
            'total_cents' => 10000,
            'points_awarded' => 20,
        ]);

        $this->actingAs($wholesaler)
            ->get(route('admin.invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_signed_public_pdf_link_works_without_login(): void
    {
        $invoice = Invoice::query()->create([
            'number' => 'INV-PDF-2',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'status' => 'approved',
            'total_cents' => 5000,
            'points_awarded' => 10,
        ]);

        $url = URL::temporarySignedRoute('invoices.pdf', now()->addDay(), ['invoice' => $invoice->id]);

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_whatsapp_message_includes_pdf_link(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'phone' => '0911111111',
            'is_active' => true,
            'is_approved' => true,
        ]);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'phone' => '0922222222',
            'is_active' => true,
            'is_approved' => true,
        ]);

        $invoice = Invoice::query()->create([
            'number' => 'INV-PDF-WA',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'status' => 'approved',
            'wholesale_distributor_id' => $wholesaler->id,
            'counterparty_user_id' => $retail->id,
            'issued_by' => $wholesaler->id,
            'points_awarded' => 15,
        ]);

        $pdfUrl = app(CustomerWhatsAppNotifier::class)->invoicePdfUrl($invoice);
        $this->assertNotEmpty($pdfUrl);
        $this->assertStringContainsString('/invoices/'.$invoice->id.'/pdf', $pdfUrl);

        $payload = app(InvoiceWhatsAppShareService::class)->forInvoice($invoice, $wholesaler);
        $this->assertStringContainsString('تحميل PDF', $payload['message']);
        $this->assertStringContainsString('/invoices/'.$invoice->id.'/pdf', $payload['message']);
    }
}

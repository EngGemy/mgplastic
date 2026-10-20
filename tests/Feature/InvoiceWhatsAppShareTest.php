<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceWhatsAppShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceWhatsAppShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_share_payload_for_outgoing_invoice(): void
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
            'number' => 'INV-TEST-1',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'status' => 'approved',
            'wholesale_distributor_id' => $wholesaler->id,
            'counterparty_user_id' => $retail->id,
            'issued_by' => $wholesaler->id,
            'total_cents' => 10000,
            'points_awarded' => 50,
        ]);

        $payload = app(InvoiceWhatsAppShareService::class)->forInvoice($invoice, $wholesaler);

        $this->assertTrue($payload['can_share']);
        $this->assertNotEmpty($payload['share_url']);
        $this->assertNotEmpty($payload['whatsapp_url']);
        $this->assertStringContainsString('wa.me', $payload['whatsapp_url']);
        $this->assertSame('0922222222', $payload['recipient']['phone']);
    }

    public function test_mobile_share_endpoint(): void
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
            'number' => 'INV-TEST-2',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'status' => 'approved',
            'wholesale_distributor_id' => $wholesaler->id,
            'counterparty_user_id' => $retail->id,
            'issued_by' => $wholesaler->id,
        ]);

        Sanctum::actingAs($wholesaler);
        $this->getJson('/api/v1/mobile/distributor/invoices/'.$invoice->id.'/share')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['share_url', 'whatsapp_url', 'recipient']]);

        $this->postJson('/api/v1/mobile/distributor/invoices/'.$invoice->id.'/share-whatsapp', [])
            ->assertOk()
            ->assertJsonPath('data.whatsapp_url', fn ($u) => is_string($u) && str_contains($u, 'wa.me'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerWhatsAppNotifier;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerWhatsAppNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_share_page_is_signed_and_shows_details(): void
    {
        $requester = User::factory()->create([
            'role' => 'plumber',
            'phone' => '0912345678',
            'name' => 'سباك تجريبي',
        ]);
        $supplier = User::factory()->create(['role' => 'retail_trader', 'name' => 'محل قطاعي']);

        $order = Order::query()->create([
            'order_number' => 'ORD-2026-000001',
            'channel' => OrderStatus::CHANNEL_RETAIL_TO_PLUMBER,
            'requester_id' => $requester->id,
            'supplier_id' => $supplier->id,
            'status' => OrderStatus::PLACED,
            'total_quantity' => 5,
            'total_points' => 20,
            'placed_at' => now(),
            'created_by' => $requester->id,
        ]);

        $url = app(CustomerWhatsAppNotifier::class)->orderShareUrl($order);
        $this->assertNotNull($url);

        $this->get($url)
            ->assertOk()
            ->assertSee('ORD-2026-000001', false)
            ->assertSee('سباك تجريبي', false);

        $this->get(route('orders.share', $order))->assertForbidden();
    }

    public function test_invoice_share_page_is_signed(): void
    {
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'phone' => '0922222222',
            'name' => 'موزع جملة',
        ]);

        $invoice = Invoice::factory()->create([
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesaler->id,
            'number' => 'MG-J-2026-1',
            'status' => 'approved',
            'points_awarded' => 100,
        ]);

        $url = app(CustomerWhatsAppNotifier::class)->invoiceShareUrl($invoice);
        $this->assertNotNull($url);

        $this->get($url)
            ->assertOk()
            ->assertSee('MG-J-2026-1', false);

        $this->get(route('invoices.share', $invoice))->assertForbidden();
    }

    public function test_order_message_includes_welcome_and_share_link(): void
    {
        Config::set('services.whatsapp.driver', 'log');

        $requester = User::factory()->create([
            'role' => 'plumber',
            'phone' => '0911111111',
            'name' => 'أحمد',
        ]);
        $supplier = User::factory()->create(['role' => 'retail_trader', 'name' => 'محل']);

        $order = Order::query()->create([
            'order_number' => 'ORD-2026-000099',
            'channel' => OrderStatus::CHANNEL_RETAIL_TO_PLUMBER,
            'requester_id' => $requester->id,
            'supplier_id' => $supplier->id,
            'status' => OrderStatus::PLACED,
            'total_quantity' => 3,
            'total_points' => 6,
            'placed_at' => now(),
            'created_by' => $requester->id,
        ]);

        $result = app(CustomerWhatsAppNotifier::class)->notifyOrderPlaced(
            $order->fresh(['requester', 'supplier']),
        );

        $this->assertNotNull($result['chat_url']);
        $this->assertStringContainsString('wa.me/218911111111', $result['chat_url']);
        $decoded = urldecode($result['chat_url']);
        $this->assertStringContainsString('أهلاً أحمد', $decoded);
        $this->assertStringContainsString('تم استلام طلبك بنجاح', $decoded);
        $this->assertStringContainsString('/orders/'.$order->id.'/share', $decoded);
    }

    public function test_welcome_chat_url_is_ready_for_free_send(): void
    {
        $trader = User::factory()->create([
            'role' => 'retail_trader',
            'phone' => '0916126065',
            'name' => 'تاجر تجريبي',
            'network_code' => 'MG-R-000099',
        ]);

        $url = app(CustomerWhatsAppNotifier::class)->welcomeChatUrl($trader);

        $this->assertNotNull($url);
        $this->assertStringContainsString('wa.me/218916126065', $url);
        $decoded = urldecode($url);
        $this->assertStringContainsString('أهلاً تاجر تجريبي', $decoded);
        $this->assertStringContainsString('سعداء بانضمامك معنا', $decoded);
        $this->assertStringContainsString('MG-R-000099', $decoded);
    }

    public function test_supplier_order_arrived_message_targets_trader(): void
    {
        $requester = User::factory()->create(['role' => 'plumber', 'name' => 'سباك', 'phone' => '0910000001']);
        $supplier = User::factory()->create([
            'role' => 'retail_trader',
            'name' => 'محل قطاعي',
            'phone' => '0910000002',
        ]);

        $order = Order::query()->create([
            'order_number' => 'ORD-2026-000200',
            'channel' => OrderStatus::CHANNEL_RETAIL_TO_PLUMBER,
            'requester_id' => $requester->id,
            'supplier_id' => $supplier->id,
            'status' => OrderStatus::PLACED,
            'total_quantity' => 2,
            'total_points' => 10,
            'placed_at' => now(),
            'created_by' => $requester->id,
        ]);

        $url = app(CustomerWhatsAppNotifier::class)->supplierOrderChatUrl(
            $order->fresh(['requester', 'supplier']),
        );

        $this->assertNotNull($url);
        $decoded = urldecode($url);
        $this->assertStringContainsString('wa.me/218910000002', $url);
        $this->assertStringContainsString('وصلك طلب جديد', $decoded);
        $this->assertStringContainsString('ORD-2026-000200', $decoded);
    }

    public function test_libyasms_driver_sends_invoice_message(): void
    {
        Config::set('services.whatsapp.driver', 'libyasms');
        Config::set('services.whatsapp.libyasms.token', 'test-token');
        Config::set('services.whatsapp.libyasms.instance_id', '99');
        Config::set('services.whatsapp.libyasms.base_url', 'https://whatsapp.libyasms.test');

        Http::fake([
            'whatsapp.libyasms.test/*' => Http::response(['ok' => true], 200),
        ]);

        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'phone' => '0916126065',
            'name' => 'عماد جملة',
        ]);

        $invoice = Invoice::factory()->create([
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesaler->id,
            'number' => 'MG-J-2026-55',
            'status' => 'approved',
            'points_awarded' => 40,
        ]);

        $result = app(CustomerWhatsAppNotifier::class)->notifyInvoiceIssued(
            $invoice->fresh(['wholesaleDistributor']),
        );

        $this->assertTrue($result['sent']);
        Http::assertSent(function ($request) use ($invoice) {
            $url = urldecode($request->url());

            return str_contains($request->url(), 'SendTextMessage')
                && str_contains($request->url(), 'instanceId=99')
                && str_contains($url, 'MG-J-2026-55')
                && str_contains($url, 'مرحباً بك في شبكة')
                && str_contains($url, '/invoices/'.$invoice->id.'/share');
        });
    }

}

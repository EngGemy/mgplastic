<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceItem;
use App\Models\NotificationInbox;
use App\Models\Product;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\HomeMobileLayoutService;
use App\Services\PendingReceiptsService;
use App\Services\RetailDistributionPosService;
use App\Services\RetailNetworkLinkService;
use App\Services\WholesalerPointsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PendingReceiptsHomeLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_layout_shows_badge_when_pending_then_clears_after_receive(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $wholesaler = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        app(RetailNetworkLinkService::class)->attach($wholesaler, $retail, $admin);

        $product = Product::factory()->create(['points_per_unit' => 2]);
        $this->seedWholesalerStock($wholesaler, $admin, $product, 10);

        Sanctum::actingAs($wholesaler);
        $res = $this->postJson('/api/v1/mobile/distributor/pos/checkout', [
            'retail_trader_id' => $retail->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertCreated();

        $invoiceId = (int) $res->json('data.invoices.0.id');

        $inbox = NotificationInbox::query()
            ->where('user_id', $retail->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($inbox);
        $this->assertSame(PendingReceiptsService::SCREEN, $inbox->data['screen'] ?? null);
        $this->assertSame(PendingReceiptsService::DEEP_LINK, $inbox->data['deep_link'] ?? null);

        Sanctum::actingAs($retail);
        app(HomeMobileLayoutService::class)->forgetCache();

        $this->getJson('/api/v1/mobile/notifications')
            ->assertOk()
            ->assertJsonPath('data.items.0.screen', PendingReceiptsService::SCREEN)
            ->assertJsonPath('data.items.0.deep_link', PendingReceiptsService::DEEP_LINK);

        $layout = $this->getJson('/api/v1/mobile/home/layout')
            ->assertOk()
            ->json('data.sections');

        $pending = collect($layout)->firstWhere('type', 'pending_receipts');
        $this->assertNotNull($pending);
        $this->assertSame(1, (int) ($pending['badge_count'] ?? 0));
        $this->assertSame(PendingReceiptsService::DEEP_LINK, $pending['settings']['deep_link'] ?? null);
        $this->assertSame(1, (int) ($pending['items'][0]['badge_count'] ?? 0));

        $quick = collect($layout)->firstWhere('type', 'shop_quick_links');
        $tile = collect($quick['items'] ?? [])->firstWhere('key', 'pendingReceipts');
        $this->assertNotNull($tile);
        $this->assertSame(1, (int) ($tile['badge_count'] ?? 0));

        $this->postJson("/api/v1/mobile/trader/invoices/{$invoiceId}/receive")
            ->assertOk()
            ->assertJsonPath('data.pending_receipt', false);

        app(HomeMobileLayoutService::class)->forgetCache();
        $after = collect($this->getJson('/api/v1/mobile/home/layout')->json('data.sections'));
        $this->assertNull($after->firstWhere('type', 'pending_receipts'));

        $quickAfter = $after->firstWhere('type', 'shop_quick_links');
        $tileAfter = collect($quickAfter['items'] ?? [])->firstWhere('key', 'pendingReceipts');
        $this->assertSame(0, (int) ($tileAfter['badge_count'] ?? 0));
    }

    public function test_plumber_layout_shows_badge_when_pending_then_clears_after_receive(): void
    {
        app(HomeMobileLayoutService::class)->ensureSeeded();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor', 'is_approved' => true, 'is_active' => true]);
        $retail = User::factory()->create(['role' => 'retail_trader', 'is_approved' => true, 'is_active' => true]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => $retail->id,
        ]);
        app(RetailNetworkLinkService::class)->attach($wholesaler, $retail, $admin);

        $product = Product::factory()->create(['points_per_unit' => 5]);
        $this->seedWholesalerStock($wholesaler, $admin, $product, 20);

        $out = app(RetailDistributionPosService::class)->issueToRetailTrader(
            $wholesaler,
            $retail,
            [['product_id' => $product->id, 'quantity' => 10]],
            $wholesaler,
        );
        app(DistributionService::class)->receiveOutgoingInvoiceAsBuyer($retail, $out[0]);

        Sanctum::actingAs($retail);
        $res = $this->postJson('/api/v1/mobile/trader/pos/checkout', [
            'plumber_id' => $plumber->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        $distId = (int) $res->json('data.distributions.0.id');

        Sanctum::actingAs($plumber);
        app(HomeMobileLayoutService::class)->forgetCache();

        $pending = collect($this->getJson('/api/v1/mobile/home/layout')->json('data.sections'))
            ->firstWhere('type', 'pending_receipts');
        $this->assertNotNull($pending);
        $this->assertSame(1, (int) ($pending['badge_count'] ?? 0));

        $this->postJson("/api/v1/mobile/plumber/distributions/{$distId}/receive")
            ->assertOk();

        app(HomeMobileLayoutService::class)->forgetCache();
        $after = collect($this->getJson('/api/v1/mobile/home/layout')->json('data.sections'));
        $this->assertNull($after->firstWhere('type', 'pending_receipts'));
        $this->assertSame('points_awarded', InvoiceDistribution::query()->find($distId)?->status);
    }

    protected function seedWholesalerStock(User $wholesaler, User $admin, Product $product, int $qty): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesaler->id,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'points_per_unit' => (float) $product->points_per_unit,
            'total_points' => (int) ($qty * $product->points_per_unit),
        ]);

        $service = app(DistributionService::class);
        $this->actingAs($admin);
        $tier1 = $service->createDistribution($invoice, $admin, $wholesaler, 1, [
            ['invoice_item_id' => $item->id, 'quantity' => $qty],
        ]);
        $service->confirmDistribution($tier1);

        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);
    }
}

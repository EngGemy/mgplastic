<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\DistributionService;
use App\Services\NetworkInventoryService;
use App\Services\RetailDistributionPosService;
use App\Services\RetailNetworkLinkService;
use App\Services\WholesalerPointsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuyerReceiptRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_wholesale_pos_stays_pending_until_retail_receives(): void
    {
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
        ])->assertCreated()
            ->assertJsonPath('data.pending_receipt', true);

        $invoiceId = (int) $res->json('data.invoices.0.id');
        $this->assertSame('pending_review', Invoice::query()->find($invoiceId)?->status);

        $dist = InvoiceDistribution::query()->where('to_user_id', $retail->id)->where('tier', 2)->first();
        $this->assertSame('draft', $dist?->status);

        // Stock reserved — seller cannot sell the reserved qty again.
        $stock = app(NetworkInventoryService::class)->stockForWholesaler($wholesaler->fresh());
        $this->assertSame(6, (int) ($stock->first()['available_qty'] ?? -1));

        // Buyer has no sellable stock yet.
        $retailStock = app(NetworkInventoryService::class)->stockForRetailTrader($retail->fresh());
        $this->assertTrue($retailStock->isEmpty() || (int) ($retailStock->first()['available_qty'] ?? 0) === 0);

        Sanctum::actingAs($retail);
        $this->getJson('/api/v1/mobile/trader/pending-receipts')
            ->assertOk()
            ->assertJsonPath('data.pending_count', 1);

        $this->postJson("/api/v1/mobile/trader/invoices/{$invoiceId}/receive")
            ->assertOk()
            ->assertJsonPath('data.pending_receipt', false);

        $this->assertSame('approved', Invoice::query()->find($invoiceId)?->fresh()->status);
        $this->assertSame('confirmed', $dist->fresh()->status);

        $retailStock = app(NetworkInventoryService::class)->stockForRetailTrader($retail->fresh());
        $this->assertSame(4, (int) ($retailStock->first()['available_qty'] ?? -1));
    }

    public function test_retail_pos_awards_points_only_after_plumber_receives(): void
    {
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

        // Move stock wholesale → retail (with buyer receive).
        $out = app(RetailDistributionPosService::class)->issueToRetailTrader(
            $wholesaler,
            $retail,
            [['product_id' => $product->id, 'quantity' => 10]],
            $wholesaler,
        );
        $inv = $out[0];
        app(DistributionService::class)->receiveOutgoingInvoiceAsBuyer($retail, $inv);

        Sanctum::actingAs($retail);
        $res = $this->postJson('/api/v1/mobile/trader/pos/checkout', [
            'plumber_id' => $plumber->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated()
            ->assertJsonPath('data.pending_receipt', true);

        $distId = (int) $res->json('data.distributions.0.id');
        $this->assertSame('draft', InvoiceDistribution::query()->find($distId)?->status);

        $walletBefore = (int) (WalletAccount::query()
            ->where('owner_id', $plumber->id)
            ->value('balance_points') ?? 0);
        $this->assertSame(0, $walletBefore);

        Sanctum::actingAs($plumber);
        $this->getJson('/api/v1/mobile/plumber/pending-receipts')
            ->assertOk()
            ->assertJsonPath('data.pending_count', 1);

        $this->postJson("/api/v1/mobile/plumber/distributions/{$distId}/receive")
            ->assertOk()
            ->assertJsonPath('data.pending_receipt', false);

        $this->assertSame('points_awarded', InvoiceDistribution::query()->find($distId)?->status);
        $walletAfter = (int) WalletAccount::query()->where('owner_id', $plumber->id)->value('balance_points');
        $this->assertSame(15, $walletAfter);
    }

    public function test_seller_cannot_confirm_buyer_receipt_via_legacy_api(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor', 'is_approved' => true, 'is_active' => true]);
        $retail = User::factory()->create(['role' => 'retail_trader', 'is_approved' => true, 'is_active' => true]);
        app(RetailNetworkLinkService::class)->attach($wholesaler, $retail, $admin);

        $product = Product::factory()->create(['points_per_unit' => 1]);
        $this->seedWholesalerStock($wholesaler, $admin, $product, 5);

        $out = app(RetailDistributionPosService::class)->issueToRetailTrader(
            $wholesaler,
            $retail,
            [['product_id' => $product->id, 'quantity' => 2]],
            $wholesaler,
        );
        $distId = (int) $out[0]->source_distribution_id;

        Sanctum::actingAs($wholesaler);
        $this->postJson("/api/v1/distributions/{$distId}/confirm")
            ->assertStatus(422);
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

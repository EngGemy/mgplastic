<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\NetworkInventoryService;
use App\Services\WholesalerPointsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_wholesaler_cannot_allocate_more_than_stock(): void
    {
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor']);
        User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create(['points_per_unit' => 5.0]);

        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesaler->id,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'points_per_unit' => 5.0,
            'total_points' => 15,
        ]);

        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        $inventory = app(NetworkInventoryService::class);
        $stock = $inventory->stockForWholesaler($wholesaler);

        $this->assertSame(3, $stock->first()['available_qty']);

        $this->expectException(\DomainException::class);
        $inventory->allocateFromStock($stock, [$product->id => 5]);
    }

    public function test_wholesaler_cannot_sell_more_points_than_wallet_balance(): void
    {
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor']);

        WalletAccount::create([
            'owner_id' => $wholesaler->id,
            'currency' => 'LYD',
            'balance_cents' => 0,
            'balance_points' => 10,
        ]);

        $inventory = app(NetworkInventoryService::class);

        $this->expectException(\DomainException::class);
        $inventory->assertPointsBalance($wholesaler, 20);
    }
}

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

    public function test_wholesaler_stock_points_are_independent_of_wallet_balance(): void
    {
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor']);
        User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create(['points_per_unit' => 5.0]);

        WalletAccount::create([
            'owner_id' => $wholesaler->id,
            'currency' => 'LYD',
            'balance_cents' => 0,
            'balance_points' => 5, // wallet low — must NOT block stock allocation
        ]);

        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesaler->id,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'points_per_unit' => 5.0,
            'total_points' => 500,
        ]);

        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        $inventory = app(NetworkInventoryService::class);
        $stock = $inventory->stockForWholesaler($wholesaler);

        $groups = $inventory->allocateFromStock($stock, [$product->id => 100]);

        $this->assertNotEmpty($groups);
        $this->assertSame(100, (int) collect($groups)->flatMap(fn ($g) => $g['items'])->sum('quantity'));
    }

    public function test_retail_trader_stock_comes_from_tier2_distribution_items(): void
    {
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor']);
        User::factory()->create(['role' => 'super_admin']);
        $retail = User::factory()->create(['role' => 'retail_trader']);
        $product = Product::factory()->create(['points_per_unit' => 2.0]);

        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesaler->id,
        ]);

        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'points_per_unit' => 2.0,
            'total_points' => 20,
        ]);

        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);

        $inventory = app(NetworkInventoryService::class);
        $groups = $inventory->allocateFromStock(
            $inventory->stockForWholesaler($wholesaler),
            [$product->id => 4],
        );

        $this->assertNotEmpty($groups);

        $distributions = app(\App\Services\DistributionService::class);
        $distribution = $distributions->createDistribution(
            invoice: $invoice,
            fromUser: $wholesaler,
            toUser: $retail,
            tier: 2,
            items: $groups[0]['items'],
            parentId: $groups[0]['parent_distribution_id'],
            skipCallerCheck: true,
        );
        $distributions->confirmDistribution($distribution->fresh(['items']));

        $retailStock = $inventory->stockForRetailTrader($retail);

        $this->assertSame(4, (int) $retailStock->first()['available_qty']);
        $this->assertSame((int) $item->id, (int) $retailStock->first()['slots'][0]['invoice_item_id']);
    }

    public function test_retail_stock_avoids_n_plus_one_available_qty_queries(): void
    {
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor']);
        User::factory()->create(['role' => 'super_admin']);
        $retail = User::factory()->create(['role' => 'retail_trader']);

        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesaler->id,
        ]);

        $products = Product::factory()->count(8)->create(['points_per_unit' => 1.0]);
        foreach ($products as $product) {
            InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'quantity' => 5,
                'points_per_unit' => 1.0,
                'total_points' => 5,
            ]);
        }

        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesaler);
        $inventory = app(NetworkInventoryService::class);
        $stock = $inventory->stockForWholesaler($wholesaler);
        $groups = $inventory->allocateFromStock(
            $stock,
            $products->mapWithKeys(fn ($p) => [$p->id => 2])->all(),
        );

        $distributions = app(\App\Services\DistributionService::class);
        $distribution = $distributions->createDistribution(
            invoice: $invoice,
            fromUser: $wholesaler,
            toUser: $retail,
            tier: 2,
            items: $groups[0]['items'],
            parentId: $groups[0]['parent_distribution_id'],
            skipCallerCheck: true,
        );
        $distributions->confirmDistribution($distribution->fresh(['items']));
        $inventory->forgetRetailStockCache($retail);

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $retailStock = $inventory->stockForRetailTrader($retail);
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(8, $retailStock);
        // Bulk path + memoized type defs: far below 2 queries × every lot line.
        $this->assertLessThan(40, count($queries), 'retail stock still looks like N+1 ('.count($queries).' queries)');
    }
}

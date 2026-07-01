<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\DistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_distribution_flow_awards_correct_points_to_plumber(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $wholesaler = User::factory()->create(['role' => 'wholesale_distributor']);
        $retailer = User::factory()->create(['role' => 'retail_trader']);
        $plumber = User::factory()->create(['role' => 'plumber']);

        $this->actingAs($superAdmin);

        $product = Product::factory()->create(['points_per_unit' => 5.0]);

        $invoice = Invoice::factory()->create(['status' => 'approved']);
        $invoiceItem = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'points_per_unit' => 5.0,
            'total_points' => 500,
        ]);

        $service = app(DistributionService::class);

        $dist1 = $service->createDistribution($invoice, $superAdmin, $wholesaler, 1, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 100],
        ]);
        $service->confirmDistribution($dist1);

        $dist2 = $service->createDistribution($invoice, $wholesaler, $retailer, 2, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 60],
        ], $dist1->id);
        $service->confirmDistribution($dist2);

        $dist3 = $service->createDistribution($invoice, $retailer, $plumber, 3, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 30],
        ], $dist2->id);
        $service->confirmDistribution($dist3);

        $this->assertSame('points_awarded', $dist3->fresh()->status);

        $wallet = WalletAccount::where('owner_id', $plumber->id)->first();
        $this->assertNotNull($wallet);
        $this->assertSame(150, $wallet->balance_points);

        $this->expectException(\DomainException::class);
        $service->createDistribution($invoice, $retailer, $plumber, 3, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 31],
        ], $dist2->id);
    }
}

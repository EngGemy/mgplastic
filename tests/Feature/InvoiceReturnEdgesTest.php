<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceReturnService;
use App\Services\WholesalerPointsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceReturnEdgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tier3_return_blocked_after_points_converted(): void
    {
        [$retail, $plumber, $distribution, $invoiceItem] = $this->seedTier3Awarded(pointsPerUnit: 10, qty: 5);

        $distItem = $distribution->items->first();
        // Award then convert all points from this distribution lot
        $plumber->wallet()->creditPoints(50, [
            'reason' => 'points_awarded',
            'distribution_id' => $distribution->id,
        ], $retail, 'seed award');

        $wallet = $plumber->wallet();
        $wallet->decrement('balance_points', 50);
        $wallet->transactions()->create([
            'type' => 'conversion',
            'amount_cents' => 1000,
            'points_delta' => -50,
            'description' => 'test convert',
            'meta' => [
                'reason' => 'conversion',
                'lots_consumed' => [[
                    'lot_key' => 'd'.$distribution->id.'-i'.$distItem->id,
                    'points' => 50,
                ]],
            ],
            'related_type' => InvoiceDistribution::class,
            'related_id' => $distribution->id,
            'created_by' => $plumber->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/صرف/');

        app(InvoiceReturnService::class)->returnPlumberDistribution($distribution->fresh(['items.invoiceItem']), [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 5],
        ], $retail);
    }

    public function test_tier2_return_does_not_mint_wallet_points(): void
    {
        [$wholesale, $retail, $invoice, $invoiceItem] = $this->seedTier2Outgoing(qty: 4, ppu: 2);

        $beforeWholesale = (int) $wholesale->wallet()->balance_points;
        $beforeRetail = (int) $retail->wallet()->balance_points;

        $return = app(InvoiceReturnService::class)->returnOutgoingInvoice($invoice, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 2],
        ], $wholesale);

        $this->assertSame(2, (int) $return->total_quantity);
        $this->assertSame($beforeWholesale, (int) $wholesale->wallet()->fresh()->balance_points);
        $this->assertSame($beforeRetail, (int) $retail->wallet()->fresh()->balance_points);
    }

    public function test_distributor_return_api_rejects_outsider(): void
    {
        [$wholesale, $retail, $invoice, $invoiceItem] = $this->seedTier2Outgoing(qty: 3, ppu: 1);
        $stranger = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($stranger);
        $this->postJson('/api/v1/mobile/distributor/invoices/'.$invoice->id.'/returns', [
            'lines' => [['invoice_item_id' => $invoiceItem->id, 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_distributor_return_api_happy_path(): void
    {
        [$wholesale, $retail, $invoice, $invoiceItem] = $this->seedTier2Outgoing(qty: 3, ppu: 1);

        Sanctum::actingAs($wholesale);
        $this->getJson('/api/v1/mobile/distributor/invoices/'.$invoice->id.'/return-preview')
            ->assertOk()
            ->assertJsonPath('data.can_return', true);

        $this->postJson('/api/v1/mobile/distributor/invoices/'.$invoice->id.'/returns', [
            'lines' => [['invoice_item_id' => $invoiceItem->id, 'quantity' => 1]],
            'note' => 'تالف',
        ])
            ->assertCreated()
            ->assertJsonPath('data.tier', 2)
            ->assertJsonPath('data.total_quantity', 1);
    }

    public function test_zero_point_product_orderable_with_zero_line_points(): void
    {
        $wholesale = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['points_per_unit' => 0]);

        Sanctum::actingAs($retail);
        $this->postJson('/api/v1/mobile/trader/orders', [
            'supplier_id' => $wholesale->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 2]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_points', 0)
            ->assertJsonPath('data.items.0.line_points', 0);

        Sanctum::actingAs($retail);
        $this->getJson('/api/v1/mobile/trader/products')
            ->assertOk();

        $item = collect($this->getJson('/api/v1/mobile/trader/products')->json('data.items'))
            ->firstWhere('id', $product->id);
        $this->assertNotNull($item);
        $this->assertFalse($item['has_points']);
        $this->assertSame('mg_no_points', $item['product_kind']);
        $this->assertTrue($item['is_mg']);
    }

    /**
     * @return array{0:User,1:User,2:InvoiceDistribution,3:InvoiceItem}
     */
    protected function seedTier3Awarded(float $pointsPerUnit, int $qty): array
    {
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $plumber = User::factory()->create([
            'role' => 'plumber',
            'is_active' => true,
            'parent_distributor_id' => $retail->id,
        ]);
        $product = Product::factory()->create(['points_per_unit' => $pointsPerUnit]);

        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
        ]);
        $invoiceItem = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'points_per_unit' => $pointsPerUnit,
            'total_points' => (int) floor($qty * $pointsPerUnit),
        ]);

        $distribution = InvoiceDistribution::query()->create([
            'invoice_id' => $invoice->id,
            'from_user_id' => $retail->id,
            'to_user_id' => $plumber->id,
            'tier' => 3,
            'status' => 'points_awarded',
            'confirmed_at' => now(),
            'points_awarded_at' => now(),
        ]);

        InvoiceDistributionItem::query()->create([
            'distribution_id' => $distribution->id,
            'invoice_item_id' => $invoiceItem->id,
            'quantity' => $qty,
            'points_value' => (int) floor($qty * $pointsPerUnit),
            'returned_quantity' => 0,
        ]);

        $distribution->load('items');

        return [$retail, $plumber, $distribution, $invoiceItem];
    }

    /**
     * @return array{0:User,1:User,2:Invoice,3:InvoiceItem}
     */
    protected function seedTier2Outgoing(int $qty, float $ppu): array
    {
        $wholesale = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
        ]);
        User::factory()->create(['role' => 'super_admin']);
        $retail = User::factory()->create([
            'role' => 'retail_trader',
            'is_approved' => true,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['points_per_unit' => $ppu]);

        $sourceInvoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesale->id,
        ]);
        $sourceItem = InvoiceItem::factory()->create([
            'invoice_id' => $sourceInvoice->id,
            'product_id' => $product->id,
            'quantity' => $qty + 10,
            'points_per_unit' => $ppu,
            'total_points' => (int) floor(($qty + 10) * $ppu),
        ]);
        app(WholesalerPointsSyncService::class)->syncForWholesaler($wholesale);

        $distribution = InvoiceDistribution::query()->create([
            'invoice_id' => $sourceInvoice->id,
            'from_user_id' => $wholesale->id,
            'to_user_id' => $retail->id,
            'tier' => 2,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        InvoiceDistributionItem::query()->create([
            'distribution_id' => $distribution->id,
            'invoice_item_id' => $sourceItem->id,
            'quantity' => $qty,
            'points_value' => (int) floor($qty * $ppu),
            'returned_quantity' => 0,
        ]);

        $outgoing = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'wholesale_distributor_id' => $wholesale->id,
            'source_distribution_id' => $distribution->id,
            'points_awarded' => (int) floor($qty * $ppu),
        ]);

        return [$wholesale, $retail, $outgoing->fresh('sourceDistribution.items'), $sourceItem];
    }
}

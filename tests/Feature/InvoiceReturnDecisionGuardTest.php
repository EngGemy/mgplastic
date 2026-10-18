<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\InvoiceReturn;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceReturnService;
use App\Services\WholesalerPointsSyncService;
use App\Support\InvoiceReturnStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceReturnDecisionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_returner_cannot_approve_own_return_seller_can_with_audit(): void
    {
        [$retail, $plumber, $distribution, $invoiceItem] = $this->seedTier3();

        $plumber->wallet()->creditPoints(40, [
            'reason' => 'points_awarded',
            'distribution_id' => $distribution->id,
        ], $retail, 'seed');

        $return = app(InvoiceReturnService::class)->returnPlumberDistribution(
            $distribution->fresh(['items.invoiceItem']),
            [['invoice_item_id' => $invoiceItem->id, 'quantity' => 1]],
            $plumber,
        );

        $this->assertSame(InvoiceReturnStatus::PENDING, InvoiceReturnStatus::normalize($return->status));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/طلب المرتجع/');

        app(InvoiceReturnService::class)->approve($return, $plumber);
    }

    public function test_seller_approves_and_writes_audit(): void
    {
        [$retail, $plumber, $distribution, $invoiceItem] = $this->seedTier3();

        $plumber->wallet()->creditPoints(40, [
            'reason' => 'points_awarded',
            'distribution_id' => $distribution->id,
        ], $retail, 'seed');

        $return = app(InvoiceReturnService::class)->returnPlumberDistribution(
            $distribution->fresh(['items.invoiceItem']),
            [['invoice_item_id' => $invoiceItem->id, 'quantity' => 1]],
            $plumber,
        );

        $approved = app(InvoiceReturnService::class)->approve($return, $retail, 'تم');

        $this->assertSame(InvoiceReturnStatus::APPROVED, InvoiceReturnStatus::normalize($approved->status));
        $this->assertSame($retail->id, (int) $approved->decided_by);
        $this->assertNotNull($approved->decided_at);
        $this->assertSame('approved', $approved->decision_audit['decision'] ?? null);

        $this->assertDatabaseHas('security_audit_logs', [
            'action' => 'invoice_return.'.InvoiceReturnStatus::APPROVED,
            'actor_id' => $retail->id,
            'subject_type' => InvoiceReturn::class,
            'subject_id' => $approved->id,
        ]);
    }

    public function test_api_blocks_returner_approve_endpoint(): void
    {
        [$retail, $plumber, $distribution, $invoiceItem] = $this->seedTier3();

        $plumber->wallet()->creditPoints(40, [
            'reason' => 'points_awarded',
            'distribution_id' => $distribution->id,
        ], $retail, 'seed');

        Sanctum::actingAs($plumber);
        $created = $this->postJson('/api/v1/mobile/plumber/distributions/'.$distribution->id.'/returns', [
            'lines' => [['invoice_item_id' => $invoiceItem->id, 'quantity' => 1]],
        ])->assertCreated();

        $returnId = $created->json('data.id');

        $this->postJson('/api/v1/mobile/plumber/returns/'.$returnId.'/approve')
            ->assertForbidden();

        Sanctum::actingAs($retail);
        $this->postJson('/api/v1/mobile/trader/returns/'.$returnId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', InvoiceReturnStatus::APPROVED);
    }

    public function test_retail_cannot_create_or_self_approve_plumber_return_via_api(): void
    {
        [$retail, $plumber, $distribution, $invoiceItem] = $this->seedTier3();

        Sanctum::actingAs($retail);
        $this->postJson('/api/v1/mobile/trader/distributions/'.$distribution->id.'/returns', [
            'lines' => [['invoice_item_id' => $invoiceItem->id, 'quantity' => 1]],
        ])->assertForbidden();

        // Even if a return exists with retail as created_by + to_user, approve is blocked.
        $return = \App\Models\InvoiceReturn::query()->create([
            'return_number' => 'RET-TEST-SELF',
            'invoice_id' => $distribution->invoice_id,
            'distribution_id' => $distribution->id,
            'from_user_id' => $plumber->id,
            'to_user_id' => $retail->id,
            'tier' => 3,
            'status' => InvoiceReturnStatus::PENDING,
            'total_quantity' => 1,
            'total_points' => 10,
            'created_by' => $retail->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/أنشأ طلب المرتجع/');
        app(InvoiceReturnService::class)->approve($return, $retail);
    }

    public function test_featured_wholesalers_and_promotions_endpoints(): void
    {
        $featured = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'is_featured_wholesaler' => true,
            'featured_sort' => 1,
            'brand_name' => 'موزع مميز',
        ]);
        User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'is_featured_wholesaler' => false,
        ]);

        $this->getJson('/api/v1/mobile/featured-wholesalers')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $featured->id)
            ->assertJsonPath('data.items.0.name', 'موزع مميز')
            ->assertJsonCount(1, 'data.items');

        $this->getJson('/api/v1/mobile/product-promotions')
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_warehouse_cannot_approve_network_return_unless_seller(): void
    {
        [$wholesale, $retail, $invoice, $invoiceItem] = $this->seedTier2();

        $warehouse = User::factory()->create([
            'role' => 'wholesale_distributor',
            'is_approved' => true,
            'is_active' => true,
            'is_central_warehouse' => true,
        ]);

        $return = app(InvoiceReturnService::class)->returnOutgoingInvoice($invoice, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 1],
        ], $retail);

        $this->expectException(\DomainException::class);
        app(InvoiceReturnService::class)->approve($return, $warehouse);
    }

    public function test_upper_party_cannot_request_return_without_lower_party(): void
    {
        [$retail, $plumber, $distribution, $invoiceItem] = $this->seedTier3();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/سباك فقط/');

        app(InvoiceReturnService::class)->returnPlumberDistribution(
            $distribution->fresh(['items.invoiceItem']),
            [['invoice_item_id' => $invoiceItem->id, 'quantity' => 1]],
            $retail,
        );
    }

    public function test_wholesale_cannot_request_tier2_return(): void
    {
        [$wholesale, $retail, $invoice, $invoiceItem] = $this->seedTier2();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/القطاعي فقط/');

        app(InvoiceReturnService::class)->returnOutgoingInvoice($invoice, [
            ['invoice_item_id' => $invoiceItem->id, 'quantity' => 1],
        ], $wholesale);
    }

    /** @return array{0:User,1:User,2:InvoiceDistribution,3:InvoiceItem} */
    protected function seedTier3(): array
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
        $product = Product::factory()->create(['points_per_unit' => 10]);

        $invoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
        ]);
        $invoiceItem = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'points_per_unit' => 10,
            'total_points' => 40,
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
            'quantity' => 4,
            'points_value' => 40,
            'returned_quantity' => 0,
        ]);

        return [$retail, $plumber, $distribution->load('items'), $invoiceItem];
    }

    /** @return array{0:User,1:User,2:Invoice,3:InvoiceItem} */
    protected function seedTier2(): array
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
        $product = Product::factory()->create(['points_per_unit' => 2]);

        $sourceInvoice = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'incoming',
            'wholesale_distributor_id' => $wholesale->id,
        ]);
        $sourceItem = InvoiceItem::factory()->create([
            'invoice_id' => $sourceInvoice->id,
            'product_id' => $product->id,
            'quantity' => 20,
            'points_per_unit' => 2,
            'total_points' => 40,
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
            'quantity' => 5,
            'points_value' => 10,
            'returned_quantity' => 0,
        ]);

        $outgoing = Invoice::factory()->create([
            'status' => 'approved',
            'invoice_type' => 'wholesale_pos',
            'invoice_flow' => 'outgoing',
            'wholesale_distributor_id' => $wholesale->id,
            'source_distribution_id' => $distribution->id,
            'points_awarded' => 10,
        ]);

        return [$wholesale, $retail, $outgoing->fresh('sourceDistribution.items'), $sourceItem];
    }
}

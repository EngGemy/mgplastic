<?php

namespace Tests\Unit;

use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductPointsRateChange;
use App\Services\Points\CatalogPointsPolicy;
use App\Services\Points\PointsRateHintBuilder;
use Tests\TestCase;

class PointsRateHintBuilderTest extends TestCase
{
    public function test_no_hint_when_catalog_matches_lot(): void
    {
        $builder = new PointsRateHintBuilder(new CatalogPointsPolicy);
        $product = new Product;
        $product->points_per_unit = 10;

        $this->assertNull($builder->forLot($product, 10.0));
    }

    public function test_hint_when_factory_raised_catalog_above_lot(): void
    {
        $builder = new PointsRateHintBuilder(new CatalogPointsPolicy);
        $product = new Product;
        $product->points_per_unit = 15;
        $product->setRelation('latestPointsRateChange', new ProductPointsRateChange([
            'id' => 9,
            'old_points_per_unit' => 10,
            'new_points_per_unit' => 15,
            'delta_points_per_unit' => 5,
        ]));

        $hint = $builder->forLot($product, 10.0);

        $this->assertNotNull($hint);
        $this->assertSame('catalog_changed_since_lot', $hint['code']);
        $this->assertSame('up', $hint['direction']);
        $this->assertSame('lot_receive', $hint['sale_uses']);
        $this->assertSame(15.0, $hint['catalog_points_per_unit']);
        $this->assertSame(10.0, $hint['lot_receive_points_per_unit']);
        $this->assertStringContainsString('رفع', $hint['title_ar']);
        $this->assertStringContainsString('15', $hint['message_ar']);
        $this->assertStringContainsString('10', $hint['message_ar']);
    }

    public function test_mobile_projection_strips_raw_rates(): void
    {
        $builder = new PointsRateHintBuilder(new CatalogPointsPolicy);
        $product = new Product;
        $product->points_per_unit = 8;
        $product->setRelation('latestPointsRateChange', null);

        $hint = $builder->forLot($product, 12.0);
        $mobile = $builder->forMobile($hint, hideRates: true);

        $this->assertSame('down', $mobile['direction']);
        $this->assertArrayNotHasKey('catalog_points_per_unit', $mobile);
        $this->assertArrayNotHasKey('lot_receive_points_per_unit', $mobile);
        $this->assertArrayHasKey('message_ar', $mobile);
        $this->assertTrue($mobile['highlighted']);
    }

    public function test_invoice_item_helper_uses_line_snapshot(): void
    {
        $builder = new PointsRateHintBuilder(new CatalogPointsPolicy);
        $product = new Product;
        $product->points_per_unit = 20;
        $product->setRelation('latestPointsRateChange', null);

        $item = new InvoiceItem(['points_per_unit' => 5]);
        $item->setRelation('product', $product);

        $hint = $builder->forInvoiceItem($item);

        $this->assertSame('up', $hint['direction']);
        $this->assertSame(5.0, $hint['lot_receive_points_per_unit']);
    }

    public function test_mixed_lots_product_hint(): void
    {
        $builder = new PointsRateHintBuilder(new CatalogPointsPolicy);
        $mixed = $builder->forProductSlots([
            [
                'code' => 'catalog_changed_since_lot',
                'direction' => 'up',
                'lot_receive_points_per_unit' => 5,
                'catalog_points_per_unit' => 12,
            ],
            [
                'code' => 'catalog_changed_since_lot',
                'direction' => 'up',
                'lot_receive_points_per_unit' => 8,
                'catalog_points_per_unit' => 12,
            ],
        ], 12.0);

        $this->assertSame('catalog_changed_mixed_lots', $mixed['code']);
        $this->assertSame('mixed', $mixed['direction']);
        $this->assertSame(2, $mixed['lots_affected']);
    }
}

<?php

namespace Database\Factories;

use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        return [
            'invoice_id' => \App\Models\Invoice::factory(),
            'product_id' => \App\Models\Product::factory(),
            'quantity' => 100,
            'unit_price_cents' => 1000,
            'points_per_unit' => 5,
            'total_points' => 500,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'product_category_id' => ProductCategory::factory(),
            'points_per_unit' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Product $product) {
            $product->translateOrNew('en')->name = fake()->words(3, true);
            $product->translateOrNew('ar')->name = 'منتج '.fake()->numberBetween(1, 999);
            $product->save();
        });
    }
}

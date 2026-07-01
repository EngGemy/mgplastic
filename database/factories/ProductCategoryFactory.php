<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    public function definition(): array
    {
        return [
            'slug' => Str::slug(fake()->unique()->words(2, true)),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ProductCategory $category) {
            $category->translateOrNew('en')->name = fake()->words(2, true);
            $category->translateOrNew('ar')->name = 'فئة '.fake()->numberBetween(1, 99);
            $category->save();
        });
    }
}

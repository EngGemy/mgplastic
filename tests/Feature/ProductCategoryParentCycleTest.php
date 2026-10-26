<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductCategoryParentCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_accessories_family_survives_parent_cycle(): void
    {
        $a = ProductCategory::query()->create(['slug' => 'pipes-a']);
        $b = ProductCategory::query()->create(['slug' => 'pipes-b', 'parent_id' => $a->id]);
        // Force a cycle without going through saving guards.
        ProductCategory::query()->whereKey($a->id)->update(['parent_id' => $b->id]);

        $a->refresh();
        $b->refresh();

        $this->assertFalse($a->isAccessoriesFamily());
        $this->assertFalse($b->isAccessoriesFamily());
    }

    public function test_parent_would_create_cycle_detects_loop(): void
    {
        $a = ProductCategory::query()->create(['slug' => 'root-a']);
        $b = ProductCategory::query()->create(['slug' => 'child-b', 'parent_id' => $a->id]);

        $this->assertTrue(ProductCategory::parentWouldCreateCycle((int) $a->id, (int) $b->id));
        $this->assertTrue(ProductCategory::parentWouldCreateCycle((int) $a->id, (int) $a->id));
        $this->assertFalse(ProductCategory::parentWouldCreateCycle((int) $b->id, (int) $a->id));
    }

    public function test_saving_rejects_cyclic_parent(): void
    {
        $a = ProductCategory::query()->create(['slug' => 'save-a']);
        $b = ProductCategory::query()->create(['slug' => 'save-b', 'parent_id' => $a->id]);

        $this->expectException(ValidationException::class);
        $a->parent_id = $b->id;
        $a->save();
    }

    public function test_break_parent_cycles_clears_loops(): void
    {
        $a = ProductCategory::query()->create(['slug' => 'fix-a']);
        $b = ProductCategory::query()->create(['slug' => 'fix-b', 'parent_id' => $a->id]);
        ProductCategory::query()->whereKey($a->id)->update(['parent_id' => $b->id]);

        $fixed = ProductCategory::breakParentCycles();

        $this->assertGreaterThanOrEqual(1, $fixed);
        $a->refresh();
        $b->refresh();
        $this->assertFalse(ProductCategory::parentWouldCreateCycle((int) $a->id, $a->parent_id ? (int) $a->parent_id : null));
        $this->assertFalse(ProductCategory::parentWouldCreateCycle((int) $b->id, $b->parent_id ? (int) $b->parent_id : null));
    }
}

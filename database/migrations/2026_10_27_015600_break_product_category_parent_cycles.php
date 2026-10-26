<?php

use App\Models\ProductCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_categories') || ! Schema::hasColumn('product_categories', 'parent_id')) {
            return;
        }

        ProductCategory::breakParentCycles();
    }

    public function down(): void
    {
        // Irreversible data repair — cyclic parent links cannot be restored safely.
    }
};

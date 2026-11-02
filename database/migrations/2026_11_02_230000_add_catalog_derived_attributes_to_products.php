<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'catalog_family')) {
                $table->string('catalog_family', 64)->nullable()->after('meta')->index();
            }
            if (! Schema::hasColumn('products', 'angle')) {
                $table->decimal('angle', 8, 2)->nullable()->after('catalog_family');
            }
            if (! Schema::hasColumn('products', 'size_value')) {
                $table->decimal('size_value', 10, 3)->nullable()->after('angle')->index();
            }
            if (! Schema::hasColumn('products', 'size_unit')) {
                $table->string('size_unit', 8)->nullable()->after('size_value'); // in|mm
            }
            if (! Schema::hasColumn('products', 'size2_value')) {
                $table->decimal('size2_value', 10, 3)->nullable()->after('size_unit');
            }
            if (! Schema::hasColumn('products', 'system')) {
                $table->string('system', 8)->nullable()->after('size2_value')->index(); // us|eu
            }
            if (! Schema::hasColumn('products', 'variant')) {
                $table->json('variant')->nullable()->after('system');
            }
            if (! Schema::hasColumn('products', 'derived_at')) {
                $table->timestamp('derived_at')->nullable()->after('variant');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['catalog_family', 'angle', 'size_value', 'size_unit', 'size2_value', 'system', 'variant', 'derived_at'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

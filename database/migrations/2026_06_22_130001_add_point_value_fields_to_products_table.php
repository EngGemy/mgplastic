<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'point_value_type')) {
                $table->enum('point_value_type', ['percent', 'fixed'])->nullable()->after('points_per_unit');
            }
            if (! Schema::hasColumn('products', 'point_value_percent')) {
                $table->decimal('point_value_percent', 8, 4)->nullable()->after('point_value_type');
            }
            if (! Schema::hasColumn('products', 'point_value_fixed')) {
                $table->decimal('point_value_fixed', 12, 4)->nullable()->after('point_value_percent')
                    ->comment('LYD per point when type=fixed');
            }
            if (! Schema::hasColumn('products', 'reference_unit_price_cents')) {
                $table->unsignedBigInteger('reference_unit_price_cents')->nullable()->after('point_value_fixed')
                    ->comment('Reference unit price for percent calculation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $cols = ['point_value_type', 'point_value_percent', 'point_value_fixed', 'reference_unit_price_cents'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

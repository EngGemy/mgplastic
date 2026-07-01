<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'points_per_unit')) {
                $table->decimal('points_per_unit', 10, 4)
                    ->unsigned()
                    ->default(0)
                    ->after('meta')
                    ->comment('النقاط لكل وحدة مباعة — يحددها السوبر أدمن');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'points_per_unit')) {
                $table->dropColumn('points_per_unit');
            }
        });
    }
};

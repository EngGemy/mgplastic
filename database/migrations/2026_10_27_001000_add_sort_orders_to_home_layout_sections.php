<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('home_layout_sections')) {
            return;
        }

        if (! Schema::hasColumn('home_layout_sections', 'sort_orders')) {
            Schema::table('home_layout_sections', function (Blueprint $table) {
                $table->json('sort_orders')->nullable()->after('sort_order');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('home_layout_sections') && Schema::hasColumn('home_layout_sections', 'sort_orders')) {
            Schema::table('home_layout_sections', function (Blueprint $table) {
                $table->dropColumn('sort_orders');
            });
        }
    }
};

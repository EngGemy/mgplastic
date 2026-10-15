<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'box_count')) {
                $table->unsignedInteger('box_count')->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('invoice_items', 'pieces_per_box')) {
                $table->unsignedInteger('pieces_per_box')->nullable()->after('box_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'pieces_per_box')) {
                $table->dropColumn('pieces_per_box');
            }
            if (Schema::hasColumn('invoice_items', 'box_count')) {
                $table->dropColumn('box_count');
            }
        });
    }
};

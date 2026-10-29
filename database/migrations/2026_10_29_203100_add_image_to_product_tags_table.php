<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_tags')) {
            return;
        }

        Schema::table('product_tags', function (Blueprint $table) {
            if (! Schema::hasColumn('product_tags', 'image')) {
                $table->string('image')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_tags') || ! Schema::hasColumn('product_tags', 'image')) {
            return;
        }

        Schema::table('product_tags', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_promotions')) {
            return;
        }

        Schema::table('product_promotions', function (Blueprint $table) {
            if (! Schema::hasColumn('product_promotions', 'video_path')) {
                $table->string('video_path')->nullable()->after('video_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_promotions') || ! Schema::hasColumn('product_promotions', 'video_path')) {
            return;
        }

        Schema::table('product_promotions', function (Blueprint $table) {
            $table->dropColumn('video_path');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_media')) {
            return;
        }

        Schema::table('store_media', function (Blueprint $table) {
            if (! Schema::hasColumn('store_media', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE store_media MODIFY COLUMN kind ENUM('banner','video','product_image','gallery','my_product') NOT NULL DEFAULT 'gallery'");
        }
        // SQLite stores kind as string — no enum alter needed.
    }

    public function down(): void
    {
        if (! Schema::hasTable('store_media')) {
            return;
        }

        if (Schema::hasColumn('store_media', 'description')) {
            Schema::table('store_media', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};

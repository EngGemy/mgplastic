<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plumber_work_photos')) {
            return;
        }

        Schema::table('plumber_work_photos', function (Blueprint $table) {
            if (! Schema::hasColumn('plumber_work_photos', 'thumbnail_path')) {
                $table->string('thumbnail_path')->nullable()->after('image');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('plumber_work_photos')) {
            return;
        }

        Schema::table('plumber_work_photos', function (Blueprint $table) {
            if (Schema::hasColumn('plumber_work_photos', 'thumbnail_path')) {
                $table->dropColumn('thumbnail_path');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('plumber_work_photos') && ! Schema::hasColumn('plumber_work_photos', 'video_path')) {
            Schema::table('plumber_work_photos', function (Blueprint $table) {
                $table->string('video_path')->nullable()->after('image');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plumber_work_photos') && Schema::hasColumn('plumber_work_photos', 'video_path')) {
            Schema::table('plumber_work_photos', function (Blueprint $table) {
                $table->dropColumn('video_path');
            });
        }
    }
};

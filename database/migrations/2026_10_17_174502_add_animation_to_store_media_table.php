<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_media', function (Blueprint $table) {
            if (! Schema::hasColumn('store_media', 'animation')) {
                $table->string('animation', 40)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_media', function (Blueprint $table) {
            if (Schema::hasColumn('store_media', 'animation')) {
                $table->dropColumn('animation');
            }
        });
    }
};

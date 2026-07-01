<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'brand_logo')) {
                $table->string('brand_logo')->nullable()->after('profile_photo');
            }
            if (! Schema::hasColumn('users', 'brand_name')) {
                $table->string('brand_name', 150)->nullable()->after('brand_logo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'brand_name')) {
                $table->dropColumn('brand_name');
            }
            if (Schema::hasColumn('users', 'brand_logo')) {
                $table->dropColumn('brand_logo');
            }
        });
    }
};

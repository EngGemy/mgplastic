<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('home_popups')) {
            return;
        }

        Schema::table('home_popups', function (Blueprint $table) {
            if (! Schema::hasColumn('home_popups', 'layout')) {
                $table->string('layout', 32)->default('hero')->after('kind');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('home_popups')) {
            return;
        }

        Schema::table('home_popups', function (Blueprint $table) {
            if (Schema::hasColumn('home_popups', 'layout')) {
                $table->dropColumn('layout');
            }
        });
    }
};

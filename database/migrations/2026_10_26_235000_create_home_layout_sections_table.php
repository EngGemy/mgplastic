<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('home_layout_sections')) {
            Schema::create('home_layout_sections', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->string('type', 64);
                $table->string('title')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('roles'); // plumber, retail_trader, wholesale_distributor, guest
                $table->unsignedInteger('limit')->nullable();
                $table->json('see_all')->nullable(); // {text, link}
                $table->json('settings')->nullable();
                $table->json('items_override')->nullable(); // optional fixed items (quick_actions / shop_quick_links)
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        if (Schema::hasTable('home_mobile_settings') && ! Schema::hasColumn('home_mobile_settings', 'layout_version')) {
            Schema::table('home_mobile_settings', function (Blueprint $table) {
                $table->unsignedInteger('layout_version')->default(1)->after('stores_title');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('home_layout_sections');

        if (Schema::hasTable('home_mobile_settings') && Schema::hasColumn('home_mobile_settings', 'layout_version')) {
            Schema::table('home_mobile_settings', function (Blueprint $table) {
                $table->dropColumn('layout_version');
            });
        }
    }
};

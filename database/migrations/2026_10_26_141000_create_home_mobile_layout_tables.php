<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('home_mobile_settings')) {
            Schema::create('home_mobile_settings', function (Blueprint $table) {
                $table->id();
                // Green banner «منتجات MG»
                $table->string('banner_title')->default('منتجات MG');
                $table->string('banner_subtitle')->nullable();
                $table->string('banner_cta_text')->default('اكتشف المزيد');
                $table->string('banner_cta_link')->nullable();
                $table->string('banner_image')->nullable();
                $table->boolean('banner_is_active')->default(true);
                // Section headings on home
                $table->string('main_sections_title')->default('الأقسام الرئيسية');
                $table->string('new_products_title')->default('جديد منتجات MG');
                $table->string('stores_title')->default('المتاجر');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('home_product_types')) {
            Schema::create('home_product_types', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->string('name');
                $table->string('subtitle')->nullable();
                $table->string('icon', 64)->default('elbow');
                $table->string('image')->nullable();
                $table->json('keywords')->nullable();
                $table->json('exclude_keywords')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('home_highlights')) {
            Schema::create('home_highlights', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->string('label');
                $table->string('icon', 64)->default('projects');
                $table->string('link')->nullable();
                $table->string('deep_link')->nullable();
                $table->json('category_match')->nullable();
                $table->json('category_prefer')->nullable();
                $table->json('category_exclude')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('home_highlights');
        Schema::dropIfExists('home_product_types');
        Schema::dropIfExists('home_mobile_settings');
    }
};

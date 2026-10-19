<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_collections')) {
            Schema::create('product_collections', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 80)->unique();
                $table->string('title');
                $table->string('subtitle')->nullable();
                $table->text('description')->nullable();
                $table->string('kind', 32)->default('custom'); // featured|new|important|custom
                $table->string('cover_image')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_collection_product')) {
            Schema::create('product_collection_product', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_collection_id')->constrained('product_collections')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->unique(['product_collection_id', 'product_id'], 'pcp_unique');
            });
        }

        if (! Schema::hasTable('promo_banners')) {
            Schema::create('promo_banners', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('subtitle')->nullable();
                $table->string('image_path')->nullable();
                $table->string('section', 64)->default('between_plumbers_stores');
                $table->string('link_type', 32)->default('none'); // none|url|product_collection|product_promotion|product
                $table->unsignedBigInteger('link_id')->nullable();
                $table->string('link_url')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['section', 'is_active', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_banners');
        Schema::dropIfExists('product_collection_product');
        Schema::dropIfExists('product_collections');
    }
};

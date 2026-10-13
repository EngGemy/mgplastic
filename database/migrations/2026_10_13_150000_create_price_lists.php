<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('pieces_per_box')->nullable()->after('classification');
            $table->decimal('piece_price_lyd', 12, 3)->nullable()->after('pieces_per_box');
            $table->decimal('piece_price_aed', 12, 3)->nullable()->after('piece_price_lyd');
            $table->decimal('box_price_lyd', 12, 3)->nullable()->after('piece_price_aed');
            $table->decimal('box_price_aed', 12, 3)->nullable()->after('box_price_lyd');
        });

        Schema::table('website_settings', function (Blueprint $table) {
            $table->json('price_list_phones')->nullable()->after('contact_phone');
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_company')->nullable();
            $table->string('customer_phone')->nullable();
            $table->date('issued_on');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->unsignedInteger('pieces_per_box')->nullable();
            $table->decimal('piece_price_lyd', 12, 3)->default(0);
            $table->decimal('piece_price_aed', 12, 3)->default(0);
            $table->decimal('box_price_lyd', 12, 3)->default(0);
            $table->decimal('box_price_aed', 12, 3)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['price_list_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');

        Schema::table('website_settings', function (Blueprint $table) {
            $table->dropColumn('price_list_phones');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'pieces_per_box',
                'piece_price_lyd',
                'piece_price_aed',
                'box_price_lyd',
                'box_price_aed',
            ]);
        });
    }
};

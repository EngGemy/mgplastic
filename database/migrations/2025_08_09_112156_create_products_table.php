<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('product_category_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_standard_id')->nullable()->constrained('product_standards')->nullOnDelete(); // 114 / 110
            $table->foreignId('product_color_id')->nullable()->constrained('product_colors')->nullOnDelete();       // Orange / Gray / White
            $table->decimal('length_m', 6, 2)->nullable(); // e.g. pipe length 6.00
            $table->string('main_image')->nullable();
            $table->json('meta')->nullable(); // stash extras if needed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

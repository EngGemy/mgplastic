<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_aliases')) {
            Schema::create('ocr_aliases', function (Blueprint $table) {
                $table->id();
                $table->string('normalized_text', 255)->unique();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedInteger('hits')->default(1);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ocr_corrections')) {
            Schema::create('ocr_corrections', function (Blueprint $table) {
                $table->id();
                $table->string('image_sha256', 64)->index();
                $table->string('raw_text', 500);
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->decimal('qty', 12, 3)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_corrections');
        Schema::dropIfExists('ocr_aliases');
    }
};

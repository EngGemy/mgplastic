<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('size_system_id')->constrained('size_systems')->cascadeOnDelete();
            $table->string('code', 32)->nullable();      // e.g. "XL", "42", "8.5"
            $table->string('label_en', 100);
            $table->string('label_ar', 100)->nullable();
            $table->smallInteger('sort')->default(0);
            $table->json('meta')->nullable();            // any extra info
            $table->timestamps();

            // fast lookup inside a system
            $table->unique(['size_system_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sizes');
    }
};

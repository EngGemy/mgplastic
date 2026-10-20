<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 120);
            $table->string('role_key', 32)->default('plumber'); // plumber|retail|wholesale|customer|engineer
            $table->string('role_label_ar', 80);
            $table->string('city_ar', 80)->nullable();
            $table->text('body_ar');
            $table->unsignedTinyInteger('rating')->default(5);
            $table->string('product_hint', 160)->nullable();
            $table->string('avatar_initials', 4)->nullable();
            $table->string('source', 16)->default('manual'); // manual|gemini|seed
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->index(['is_active', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('home_popups')) {
            return;
        }

        Schema::create('home_popups', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('image_path');
            $table->string('kind', 32)->default('promo'); // promo|announcement|event|feature|auth
            $table->string('cta_text');
            $table->string('link_type', 48)->default('home');
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('link_path')->nullable(); // free deep-link path e.g. auth/login, wallet
            $table->string('link_url')->nullable(); // external https
            $table->json('target_roles'); // guest|plumber|retail_trader|wholesale_distributor
            $table->string('frequency', 24)->default('once'); // every_open|once|once_per_day
            $table->boolean('dismissible')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_popups');
    }
};

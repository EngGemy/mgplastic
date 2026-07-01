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
        Schema::create('social_media_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_media_id')->constrained('social_media')->onDelete('cascade');
            $table->string('locale')->index();
            $table->string('name');
            $table->unique(['social_media_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_media_translations');
    }
};

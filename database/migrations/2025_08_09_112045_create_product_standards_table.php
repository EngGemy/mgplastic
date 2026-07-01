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
        Schema::create('product_standards', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();      // e.g. "114", "110"
            $table->string('name_en');             // "American System (114)"
            $table->string('name_ar');             // "النظام الأمريكي (114)"
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_standards');
    }
};

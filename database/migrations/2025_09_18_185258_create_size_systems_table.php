<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('size_systems', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();      // 'us', 'eu'
            $table->string('name_en', 50);
            $table->string('name_ar', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('size_systems');
    }
};

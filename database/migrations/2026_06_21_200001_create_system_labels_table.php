<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_labels', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('default_value');
            $table->string('custom_value')->nullable();
            $table->string('group')->default('general');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_labels');
    }
};

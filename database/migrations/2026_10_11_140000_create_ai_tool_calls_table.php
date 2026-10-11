<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->string('tool', 120)->index();
            $table->json('arguments')->nullable();
            $table->boolean('ok')->default(true);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error')->nullable();
            $table->json('result_meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
    }
};

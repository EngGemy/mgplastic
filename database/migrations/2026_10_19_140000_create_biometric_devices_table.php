<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('biometric_devices')) {
            return;
        }

        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_id', 120);
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 32)->nullable(); // ios|android
            $table->text('public_key')->nullable(); // PEM — optional signed challenge
            $table->string('unlock_token_hash', 64)->nullable(); // sha256 of one-time shown unlock token
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
            $table->index(['device_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};

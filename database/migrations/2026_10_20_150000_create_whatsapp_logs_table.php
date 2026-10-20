<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64)->nullable()->index();
            $table->string('phone', 32)->index();
            $table->string('recipient_type', 16)->default('customer'); // customer|admin|otp
            $table->string('status', 32)->index(); // sent|failed|skipped_limit|skipped_invalid_phone|fallback_text
            $table->string('channel', 16)->nullable(); // image|text
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_logs');
    }
};

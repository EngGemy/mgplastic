<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wholesaler_join_requests')) {
            return;
        }

        Schema::create('wholesaler_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesaler_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('retail_trader_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|cancelled
            $table->string('message', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['wholesaler_id', 'status']);
            $table->index(['retail_trader_id', 'status']);
            $table->index(['wholesaler_id', 'retail_trader_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesaler_join_requests');
    }
};

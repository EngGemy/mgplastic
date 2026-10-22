<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status', 20)->default('info'); // info|success|warning|danger
            $table->string('audience', 40); // user|users|role|plumbers|retailers|wholesalers|all|custom
            $table->string('role', 40)->nullable();
            $table->json('user_ids')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->string('url', 500)->nullable();
            $table->string('action_label', 120)->nullable();
            $table->string('push_type', 40)->nullable();
            $table->string('push_id', 80)->nullable();
            $table->json('meta')->nullable();
            $table->boolean('sent_push')->default(true);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['audience', 'sent_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_broadcasts');
    }
};

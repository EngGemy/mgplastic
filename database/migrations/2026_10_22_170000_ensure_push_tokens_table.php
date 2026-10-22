<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair: recreate push_tokens if dropped on production. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_tokens')) {
            Schema::create('push_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('device_id', 191);
                $table->string('token', 512);
                $table->string('platform', 16); // ios | android
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique('device_id');
                $table->index('token');
                $table->index(['user_id', 'platform']);
                $table->index(['user_id', 'is_active']);
            });

            return;
        }

        Schema::table('push_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('push_tokens', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('platform');
            }
            if (! Schema::hasColumn('push_tokens', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Repair only — never drop.
    }
};

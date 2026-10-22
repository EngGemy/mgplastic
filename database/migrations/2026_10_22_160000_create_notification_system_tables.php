<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_inbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->string('priority', 16)->default('normal'); // high|normal
            $table->string('idempotency_key', 191)->nullable();
            $table->string('filament_notification_id', 36)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('type');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->boolean('push')->default(true);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications_inbox')->cascadeOnDelete();
            $table->foreignId('device_token_id')->nullable()->constrained('push_tokens')->nullOnDelete();
            $table->string('status', 32); // queued|sent|failed|unregistered
            $table->string('fcm_message_id', 191)->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['notification_id', 'status']);
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'quiet_from')) {
                $table->time('quiet_from')->nullable()->after('deactivated_at');
            }
            if (! Schema::hasColumn('users', 'quiet_to')) {
                $table->time('quiet_to')->nullable()->after('quiet_from');
            }
            if (! Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('quiet_to');
            }
            if (! Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 8)->nullable()->after('timezone');
            }
        });

        Schema::table('push_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('push_tokens', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('platform');
                $table->index(['user_id', 'is_active']);
            }
        });

        Schema::table('notification_broadcasts', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_broadcasts', 'schedule_at')) {
                $table->timestamp('schedule_at')->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('notification_broadcasts', 'segment')) {
                $table->json('segment')->nullable()->after('user_ids');
            }
            if (! Schema::hasColumn('notification_broadcasts', 'preview_count')) {
                $table->unsignedInteger('preview_count')->nullable()->after('recipients_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_broadcasts', function (Blueprint $table) {
            foreach (['schedule_at', 'segment', 'preview_count'] as $col) {
                if (Schema::hasColumn('notification_broadcasts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('push_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('push_tokens', 'is_active')) {
                $table->dropIndex(['user_id', 'is_active']);
                $table->dropColumn('is_active');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['quiet_from', 'quiet_to', 'timezone', 'locale'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications_inbox');
    }
};

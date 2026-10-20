<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plumber_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('level', 16)->default('bronze');
            $table->json('components')->nullable();
            $table->unsignedInteger('confirmed_distributions_count')->default(0);
            $table->timestamps();
        });

        Schema::create('plumber_score_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->integer('points_delta')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('tier', 16)->default('common');
            $table->json('rule')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at')->useCurrent();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unique(['user_id', 'badge_id']);
        });

        Schema::create('plumber_streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('current_weeks')->default(0);
            $table->unsignedInteger('best_weeks')->default(0);
            $table->date('last_active_week')->nullable();
            $table->unsignedTinyInteger('freezes_available')->default(1);
            $table->date('last_freeze_reset')->nullable();
            $table->timestamps();
        });

        Schema::create('warranty_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('plumber_id')->constrained('users')->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_phone_hash', 64)->index();
            $table->string('site_address')->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->text('work_description')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('status', 16)->default('active')->index();
            $table->string('revoked_reason')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('card_path')->nullable();
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('warranty_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('distribution_id')->constrained('invoice_distributions')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('warranty_months')->default(12);
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_id')->constrained()->cascadeOnDelete();
            $table->text('customer_note')->nullable();
            $table->json('photos')->nullable();
            $table->string('status', 16)->default('open')->index();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reminder_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('weekly_enabled')->default(true);
            $table->boolean('daily_points_enabled')->default(true);
            $table->json('quiet_hours')->nullable();
            $table->timestamp('last_weekly_sent_at')->nullable();
            $table->timestamp('last_daily_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16);
            $table->string('channel', 16)->nullable();
            $table->boolean('ai_used')->default(false);
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('ai_message_cache', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('kind', 32);
            $table->text('text');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_message_cache');
        Schema::dropIfExists('reminder_logs');
        Schema::dropIfExists('reminder_preferences');
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('warranty_items');
        Schema::dropIfExists('warranties');
        Schema::dropIfExists('warranty_settings');
        Schema::dropIfExists('plumber_streaks');
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('plumber_score_events');
        Schema::dropIfExists('plumber_scores');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('distributor_id')->nullable()->index();
            $table->string('status', 32)->default('draft')->index(); // draft|reading|review|added|abandoned
            $table->string('idempotency_token', 64)->nullable()->unique();
            $table->timestamp('cart_added_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ocr_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('ocr_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('path');
            $table->string('sha256', 64)->index();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 32)->default('queued')->index(); // queued|reading|retrying|done|empty|failed|canceled
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error_code', 32)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->string('model_used')->nullable();
            $table->unsignedInteger('lines_count')->default(0);
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->unsignedTinyInteger('read_version')->default(0);
            $table->boolean('quality_warning')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Idempotency is enforced in app (skip same sha unless user forces replace).
            $table->index(['batch_id', 'sha256']);
            $table->index(['batch_id', 'status']);
        });

        Schema::create('ocr_image_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('image_id')->constrained('ocr_images')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(0);
            $table->text('raw_fragment');
            $table->unsignedBigInteger('resolved_product_id')->nullable()->index();
            $table->decimal('qty', 12, 3)->nullable();
            $table->string('review_status', 32)->default('needs')->index(); // ready|needs|approved|excluded|added
            $table->unsignedTinyInteger('version')->default(1);
            $table->json('payload')->nullable(); // slots, candidates, needs_review, …
            $table->timestamps();

            $table->index(['image_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_image_lines');
        Schema::dropIfExists('ocr_images');
        Schema::dropIfExists('ocr_batches');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ocr_term_aliases')) {
            return;
        }

        Schema::create('ocr_term_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('term_normalized', 120);
            $table->string('family_key', 64);
            $table->string('source', 16)->default('user'); // seed|user|admin|gemini
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('distinct_users')->default(0);
            $table->json('user_ids')->nullable();
            $table->string('example_raw', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['term_normalized', 'family_key'], 'ocr_term_aliases_term_family_unique');
            $table->index(['status', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_term_aliases');
    }
};

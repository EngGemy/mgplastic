<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('point_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_store_id')->nullable()->constrained('plumber_stores')->nullOnDelete();
            $t->enum('type', ['percent','fixed']);
            $t->decimal('percent_rate', 8, 4)->nullable();   // <- was unsignedDecimal
            $t->integer('fixed_points')->nullable();
            $t->bigInteger('min_total_cents')->nullable();
            $t->bigInteger('max_total_cents')->nullable();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('name')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('point_rules');
    }
};

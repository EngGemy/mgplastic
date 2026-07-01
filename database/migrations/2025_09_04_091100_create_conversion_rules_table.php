<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('conversion_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_store_id')->nullable()->constrained('plumber_stores')->nullOnDelete(); // null = global
            $t->string('currency', 3)->default('SAR');
            $t->integer('points_per_currency_unit')->default(100); // 100 pts = 1 SAR
            $t->integer('min_redeem_points')->default(100);
            $t->integer('max_redeem_points')->nullable();
            $t->decimal('fee_percent', 6, 3)->default(0);  // % fee on gross money
            $t->bigInteger('fee_fixed_cents')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('conversion_rules');
    }
};

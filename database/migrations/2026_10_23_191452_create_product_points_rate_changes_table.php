<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_points_rate_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('old_points_per_unit', 12, 4);
            $table->decimal('new_points_per_unit', 12, 4);
            $table->decimal('delta_points_per_unit', 12, 4);
            $table->decimal('change_percent', 10, 2)->nullable();
            $table->unsignedInteger('wholesale_remaining_qty')->default(0);
            $table->unsignedInteger('retail_remaining_qty')->default(0);
            $table->unsignedInteger('wholesale_holders')->default(0);
            $table->unsignedInteger('retail_holders')->default(0);
            $table->bigInteger('estimated_points_delta')->default(0);
            $table->string('note', 500)->nullable();
            $table->json('impact_snapshot')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_points_rate_changes');
    }
};

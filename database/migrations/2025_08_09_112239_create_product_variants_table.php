<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');

            // Catalog code on the table row (especially accessories)
            $table->string('catalog_code')->nullable(); // e.g. "00095", "00174", ...

            // Common dimensions/props seen in the tables
            $table->decimal('outer_diameter_mm', 8, 2)->nullable();  // D
            $table->decimal('wall_thickness_mm', 8, 2)->nullable();  // S
            $table->decimal('insertion_depth_mm', 8, 2)->nullable(); // EP
            $table->decimal('weight_kg_per_m', 8, 3)->nullable();    // KG/M
            $table->string('pressure_class')->nullable();            // e.g. "Pn6", "Pn10", "Pn16" (for high-pressure)

            // Accessory-specific fields from tables
            $table->decimal('width_w_mm', 8, 2)->nullable();         // W
            $table->decimal('height_l_mm', 8, 2)->nullable();        // L (some tables call height=L)
            $table->decimal('depth_h_mm', 8, 2)->nullable();         // H
            $table->decimal('depth_h1_mm', 8, 2)->nullable();        // H1
            $table->decimal('depth_h2_mm', 8, 2)->nullable();        // H2
            $table->decimal('depth_h3_mm', 8, 2)->nullable();        // H3
            $table->decimal('d1_mm', 8, 2)->nullable();
            $table->decimal('d2_mm', 8, 2)->nullable();
            $table->decimal('d3_mm', 8, 2)->nullable();
            $table->decimal('d4_mm', 8, 2)->nullable();

            $table->json('extra')->nullable(); // if a row has oddball fields, stash here
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factory_customers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('company_name');
            $table->index('phone');
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->foreignId('factory_customer_id')
                ->nullable()
                ->after('subtitle')
                ->constrained('factory_customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factory_customer_id');
        });

        Schema::dropIfExists('factory_customers');
    }
};

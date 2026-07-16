<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoice_distribution_items', 'returned_quantity')) {
            Schema::table('invoice_distribution_items', function (Blueprint $table) {
                $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
            });
        }

        if (! Schema::hasTable('invoice_returns')) {
            Schema::create('invoice_returns', function (Blueprint $table) {
                $table->id();
                $table->string('return_number', 40)->unique();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->foreignId('distribution_id')->nullable()->constrained('invoice_distributions')->nullOnDelete();
                $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete(); // من يعيد البضاعة
                $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();   // من يستلم المرتجع
                $table->unsignedTinyInteger('tier'); // 2 = قطاعي→جملة ، 3 = سباك→قطاعي
                $table->string('status', 32)->default('confirmed'); // confirmed immediately for now
                $table->unsignedInteger('total_quantity')->default(0);
                $table->unsignedInteger('total_points')->default(0);
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->index(['invoice_id', 'status']);
                $table->index(['distribution_id']);
            });
        }

        if (! Schema::hasTable('invoice_return_items')) {
            Schema::create('invoice_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_return_id')->constrained('invoice_returns')->cascadeOnDelete();
                $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
                $table->foreignId('distribution_item_id')->nullable()->constrained('invoice_distribution_items')->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->unsignedInteger('quantity');
                $table->unsignedInteger('points_value')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_return_items');
        Schema::dropIfExists('invoice_returns');

        if (Schema::hasColumn('invoice_distribution_items', 'returned_quantity')) {
            Schema::table('invoice_distribution_items', function (Blueprint $table) {
                $table->dropColumn('returned_quantity');
            });
        }
    }
};

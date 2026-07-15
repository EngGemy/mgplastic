<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_number')->nullable()->unique();

                // factory_to_wholesale | wholesale_to_retail
                $table->string('channel', 40)->index();

                // buyer / seller
                $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained('users')->nullOnDelete();

                // placed | confirmed | shipping | delivered | cancelled | rejected
                $table->string('status', 20)->default('placed')->index();

                $table->unsignedInteger('total_quantity')->default(0);
                $table->unsignedInteger('total_points')->default(0);

                // shipping / tracking
                $table->string('carrier_name')->nullable();
                $table->string('tracking_number')->nullable();
                $table->date('expected_delivery_at')->nullable();

                // notes
                $table->text('note')->nullable();          // buyer note
                $table->text('supplier_note')->nullable();  // confirm / reject reason

                // lifecycle timestamps
                $table->timestamp('placed_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();

                // actors
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

                // fulfilment links (created on delivery via the existing distribution pipeline)
                $table->foreignId('delivered_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->json('delivered_reference')->nullable();

                $table->timestamps();

                $table->index(['requester_id', 'status']);
                $table->index(['supplier_id', 'status']);
            });
        }

        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('name_snapshot')->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('points_per_unit', 12, 2)->default(0);
                $table->unsignedInteger('line_points')->default(0);
                $table->timestamps();

                $table->index(['order_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};

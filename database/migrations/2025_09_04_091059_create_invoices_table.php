<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plumber_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('vendor_store_id')->nullable()->constrained('plumber_stores')->nullOnDelete(); // your vendor stores table
            $t->bigInteger('subtotal_cents')->unsigned();
            $t->bigInteger('tax_cents')->default(0)->unsigned();
            $t->bigInteger('total_cents')->unsigned();
            $t->string('currency', 13)->default('dinnar');
            $t->string('number')->nullable();      // external invoice number
            $t->string('attachment_path');         // image/pdf path in public disk
            $t->enum('status', ['pending_review','approved','rejected'])->default('pending_review');
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('rejection_reason')->nullable(); // {en, ar}
            $t->timestamps();

            $t->index(['plumber_id','status']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('invoices');
    }
};

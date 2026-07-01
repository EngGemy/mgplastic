<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('withdrawal_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plumber_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('wallet_account_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('amount_cents')->unsigned();
            $t->enum('status', ['pending','approved','rejected','paid'])->default('pending');
            $t->enum('method', ['bank_transfer','mobile_wallet'])->default('bank_transfer');
            $t->json('details')->nullable(); // {iban:..., name:...} or {phone:...}
            $t->json('rejection_reason')->nullable(); // {en, ar}
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();

            $t->index(['plumber_id','status']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('withdrawal_requests');
    }
};

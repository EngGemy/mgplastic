<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('withdrawal_requests')) {
            Schema::create('withdrawal_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plumber_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('wallet_account_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('amount_cents');
                $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
                $table->enum('method', ['bank_transfer', 'mobile_wallet'])->default('bank_transfer');
                $table->json('details')->nullable();
                $table->json('rejection_reason')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('receipt_number', 100)->nullable();
                $table->string('transfer_number', 100)->nullable();
                $table->timestamps();

                $table->index(['plumber_id', 'status']);
            });

            return;
        }

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawal_requests', 'status')) {
                $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
            }

            if (! Schema::hasColumn('withdrawal_requests', 'method')) {
                $table->enum('method', ['bank_transfer', 'mobile_wallet'])->default('bank_transfer');
            }

            if (! Schema::hasColumn('withdrawal_requests', 'details')) {
                $table->json('details')->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'rejection_reason')) {
                $table->json('rejection_reason')->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'paid_at')) {
                $table->timestamp('paid_at')->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'receipt_number')) {
                $table->string('receipt_number', 100)->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'transfer_number')) {
                $table->string('transfer_number', 100)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Schema repair migration — no rollback.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production repair: older withdrawal_requests tables may lack
 * wallet_account_id / amount_cents even when prior migrations are marked ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('withdrawal_requests')) {
            Schema::create('withdrawal_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plumber_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('wallet_account_id')->constrained('wallet_accounts')->cascadeOnDelete();
                $table->unsignedBigInteger('amount_cents')->default(0);
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
            if (! Schema::hasColumn('withdrawal_requests', 'wallet_account_id')) {
                $table->unsignedBigInteger('wallet_account_id')->nullable()->index();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'amount_cents')) {
                $table->unsignedBigInteger('amount_cents')->default(0);
            }

            if (! Schema::hasColumn('withdrawal_requests', 'plumber_id')) {
                $table->unsignedBigInteger('plumber_id')->nullable()->index();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'receipt_number')) {
                $table->string('receipt_number', 100)->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'transfer_number')) {
                $table->string('transfer_number', 100)->nullable();
            }
        });

        // Backfill wallet_account_id from plumber wallets when possible
        if (
            Schema::hasColumn('withdrawal_requests', 'wallet_account_id')
            && Schema::hasColumn('withdrawal_requests', 'plumber_id')
            && Schema::hasTable('wallet_accounts')
        ) {
            DB::statement("
                UPDATE withdrawal_requests wr
                INNER JOIN wallet_accounts wa
                    ON wa.owner_id = wr.plumber_id AND wa.currency = 'LYD'
                SET wr.wallet_account_id = wa.id
                WHERE wr.wallet_account_id IS NULL
            ");
        }
    }

    public function down(): void
    {
        // Repair migration — keep columns.
    }
};
